<?php

namespace Tests\Feature;

use App\Actions\Devices\RunBulkUpgrade;
use App\Actions\Devices\UpgradeDevice;
use App\Actions\Devices\UpgradePreflight;
use App\Enums\DeviceStatus;
use App\Enums\PollMethod;
use App\Enums\UpgradeStatus;
use App\Models\Credential;
use App\Models\Device;
use App\Support\DeviceHierarchy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeRebootWaiter;
use Tests\Support\FakeRouterOsClient;
use Tests\TestCase;

class RunBulkUpgradeTest extends TestCase
{
    use RefreshDatabase;

    private Credential $cred;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cred = Credential::factory()->routeros()->create();
    }

    private function device(string $ip, ?int $parentId = null, PollMethod $method = PollMethod::RouterOs): Device
    {
        return Device::factory()->create([
            'mgmt_ip' => $ip,
            'parent_device_id' => $parentId,
            'poll_method' => $method,
            'credential_id' => $this->cred->id,
        ]);
    }

    private function clientWithUpdate(): FakeRouterOsClient
    {
        return new FakeRouterOsClient(replies: [
            '/system/package/update/print' => [['installed-version' => '7.20.7', 'latest-version' => '7.20.8']],
            '/system/resource/print' => [['version' => '7.20.8']],
        ]);
    }

    private function orchestrator(FakeRouterOsClient $client, FakeRebootWaiter $waiter): RunBulkUpgrade
    {
        return new RunBulkUpgrade(new UpgradeDevice($client, $waiter), new UpgradePreflight(new DeviceHierarchy));
    }

    public function test_upgrades_downstream_first_waiting_for_each_to_recover(): void
    {
        $root = $this->device('10.0.0.1');
        $mid = $this->device('10.0.0.2', $root->id);
        $leaf = $this->device('10.0.0.3', $mid->id);

        $waiter = new FakeRebootWaiter;
        // Selection in arbitrary order - must be processed leaf -> mid -> root.
        $this->orchestrator($this->clientWithUpdate(), $waiter)([$root->id, $leaf->id, $mid->id]);

        $this->assertSame([$leaf->id, $mid->id, $root->id], $waiter->awaited);
        $this->assertSame(UpgradeStatus::Done, $root->fresh()->upgrade_status);
    }

    public function test_skips_devices_that_fail_dependency_checks(): void
    {
        $root = $this->device('10.0.0.1');                              // up-ish, upgrades
        $orphan = $this->device('10.0.0.9');                            // up-ish, upgrades
        $downChild = $this->device('10.0.0.2', $root->id);
        $downChild->update(['status' => DeviceStatus::Down]);          // down -> skip
        $deadParent = $this->device('10.0.0.3');
        $deadParent->update(['status' => DeviceStatus::Down]);         // down -> skip
        $childOfDead = $this->device('10.0.0.4', $deadParent->id);     // parent down -> skip

        $waiter = new FakeRebootWaiter;
        $this->orchestrator($this->clientWithUpdate(), $waiter)(
            [$root->id, $orphan->id, $downChild->id, $deadParent->id, $childOfDead->id]
        );

        // Only the reachable, up devices were actually upgraded.
        $this->assertContains($root->id, $waiter->awaited);
        $this->assertContains($orphan->id, $waiter->awaited);
        $this->assertNotContains($downChild->id, $waiter->awaited);
        $this->assertNotContains($deadParent->id, $waiter->awaited);
        $this->assertNotContains($childOfDead->id, $waiter->awaited);

        $this->assertSame(UpgradeStatus::Failed, $downChild->fresh()->upgrade_status);
        $this->assertSame(UpgradeStatus::Failed, $childOfDead->fresh()->upgrade_status);
        $this->assertStringContainsString('down', (string) $childOfDead->fresh()->upgrade_message);
    }

    public function test_isolates_a_failing_device_and_continues(): void
    {
        $root = $this->device('10.0.0.1');
        $badChild = $this->device('10.0.0.2', $root->id, PollMethod::Snmp); // not upgradable

        $waiter = new FakeRebootWaiter;
        $this->orchestrator($this->clientWithUpdate(), $waiter)([$root->id, $badChild->id]);

        $this->assertSame([$root->id], $waiter->awaited); // only the RouterOS root was upgraded
        $this->assertSame(UpgradeStatus::Failed, $badChild->fresh()->upgrade_status);
        $this->assertSame(UpgradeStatus::Done, $root->fresh()->upgrade_status);
    }
}
