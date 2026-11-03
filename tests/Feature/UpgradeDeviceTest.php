<?php

namespace Tests\Feature;

use App\Actions\Devices\UpgradeDevice;
use App\Enums\DeviceStatus;
use App\Enums\PollMethod;
use App\Enums\UpgradeStatus;
use App\Jobs\BulkUpgradeJob;
use App\Jobs\UpgradeDeviceJob;
use App\Models\Credential;
use App\Models\Device;
use App\Models\RouterosPackage;
use App\Services\RouterOs\RouterOsClientException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeRebootWaiter;
use Tests\Support\FakeRouterOsClient;
use Tests\TestCase;

class UpgradeDeviceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsUser();
    }

    private function routerOsDevice(): Device
    {
        $credential = Credential::factory()->routeros()->create();

        return Device::factory()->create([
            'poll_method' => PollMethod::RouterOs,
            'credential_id' => $credential->id,
        ]);
    }

    /** A device with a newer release available. */
    private function clientWithUpdate(): FakeRouterOsClient
    {
        return new FakeRouterOsClient(replies: [
            '/system/package/update/print' => [['installed-version' => '7.20.7', 'latest-version' => '7.20.8']],
            '/system/resource/print' => [['version' => '7.20.8 (stable)']],
        ]);
    }

    public function test_upgrades_when_a_newer_version_is_available(): void
    {
        $device = $this->routerOsDevice();
        $client = $this->clientWithUpdate();
        $waiter = new FakeRebootWaiter(result: true);

        (new UpgradeDevice($client, $waiter))($device);

        // First connection ran check -> download -> reboot.
        $cmds = $client->opened[0]->commands();
        $this->assertContains('/system/package/update/download', $cmds);
        $this->assertContains('/system/reboot', $cmds);

        $device->refresh();
        $this->assertSame(UpgradeStatus::Done, $device->upgrade_status);
        $this->assertSame('7.20.8', $device->os_version);   // re-read + normalised after reboot
        $this->assertSame('7.20.8', $device->latest_version);
        $this->assertTrue($device->os_version === $device->latest_version); // on latest
        $this->assertSame([$device->id], $waiter->awaited); // waited for it to come back
    }

    public function test_targeted_upgrade_fetches_the_npk_to_the_device_and_reboots(): void
    {
        $device = $this->routerOsDevice();
        $device->update(['arch' => 'arm', 'os_version' => '7.20.7']);
        $client = new FakeRouterOsClient(replies: [
            '/system/resource/print' => [['version' => '7.20.7 (stable)', 'architecture-name' => 'arm']],
            '/system/package/print' => [['name' => 'routeros', 'version' => '7.20.7']],
            // The fetched package is present on the device (so we reboot).
            '/file/print' => [['name' => 'routeros-7.20.9-arm.npk', 'size' => '11557064']],
        ]);

        (new UpgradeDevice($client, $this->comesBackOn($client, '7.20.9')))($device, '7.20.9', 'mikrotik');

        $cmds = $client->opened[0]->commands();
        $this->assertContains('/tool/fetch', $cmds);
        $this->assertContains('/system/reboot', $cmds);
        $device->refresh();
        $this->assertSame(UpgradeStatus::Done, $device->upgrade_status);
        $this->assertSame('7.20.9', $device->os_version);
    }

    /** A waiter that has the router report $version once it's back, as a real upgrade would. */
    private function comesBackOn(FakeRouterOsClient $client, string $version): FakeRebootWaiter
    {
        return new FakeRebootWaiter(result: true, onRecover: function () use ($client, $version): void {
            $client->replies['/system/resource/print'] = [['version' => "{$version} (stable)", 'architecture-name' => 'arm']];
        });
    }

    /** The fetch URLs the upgrade sent to the router, in order. */
    private function fetchedUrls(FakeRouterOsClient $client): array
    {
        return array_values(array_map(
            fn (array $q): string => $q['params']['url'],
            array_filter($client->opened[0]->queries, fn (array $q): bool => $q['command'] === '/tool/fetch'),
        ));
    }

    public function test_targeted_upgrade_stages_every_installed_package_not_just_routeros(): void
    {
        // A cAP ac: RouterOS refuses to install a new routeros without wifi-qcom-ac at the same
        // version, logs "missing package wifi-qcom-ac" and boots the old one (the bug a customer
        // hit). Every installed package has to be staged.
        $device = $this->routerOsDevice();
        $device->update(['arch' => 'arm', 'os_version' => '7.23.8']);
        $client = new FakeRouterOsClient(replies: [
            '/system/resource/print' => [['version' => '7.23.8 (stable)', 'architecture-name' => 'arm']],
            '/system/package/print' => [
                ['name' => 'routeros', 'version' => '7.23.8'],
                ['name' => 'wifi-qcom-ac', 'version' => '7.23.8'],
                ['name' => 'container', 'version' => '7.23.8', 'disabled' => 'true'],
                ['name' => 'iot', 'version' => '7.23.8', 'scheduled' => 'scheduled for uninstall'],
            ],
            '/file/print' => [
                ['name' => 'routeros-7.24.6-arm.npk', 'size' => '13000000'],
                ['name' => 'wifi-qcom-ac-7.24.6-arm.npk', 'size' => '3000000'],
                ['name' => 'container-7.24.6-arm.npk', 'size' => '1100000'],
            ],
        ]);

        (new UpgradeDevice($client, $this->comesBackOn($client, '7.24.6')))($device, '7.24.6', 'mikrotik');

        $this->assertSame([
            'https://download.mikrotik.com/routeros/7.24.6/routeros-7.24.6-arm.npk',
            'https://download.mikrotik.com/routeros/7.24.6/wifi-qcom-ac-7.24.6-arm.npk',
            'https://download.mikrotik.com/routeros/7.24.6/container-7.24.6-arm.npk', // disabled still counts
        ], $this->fetchedUrls($client)); // iot is being uninstalled, so not staged
        $this->assertContains('/system/reboot', $client->opened[0]->commands());
        $this->assertSame(UpgradeStatus::Done, $device->refresh()->upgrade_status);
    }

    public function test_targeted_upgrade_does_not_reboot_when_an_extra_package_is_missing_and_cleans_up(): void
    {
        $device = $this->routerOsDevice();
        $device->update(['arch' => 'arm', 'os_version' => '7.23.8']);
        $client = new FakeRouterOsClient(replies: [
            '/system/resource/print' => [['version' => '7.23.8', 'architecture-name' => 'arm']],
            '/system/package/print' => [['name' => 'routeros'], ['name' => 'wifi-qcom-ac']],
            // routeros landed; wifi-qcom-ac didn't (a 404 for this version, say).
            '/file/print' => [['name' => 'routeros-7.24.6-arm.npk', 'size' => '13000000']],
        ]);

        (new UpgradeDevice($client, new FakeRebootWaiter(result: true)))($device, '7.24.6', 'mikrotik');

        $queries = $client->opened[0]->queries;
        $this->assertNotContains('/system/reboot', array_column($queries, 'command'));
        // Both staged files are removed, so a later reboot can't half-apply the upgrade.
        $removed = array_column(array_column(array_filter($queries, fn (array $q): bool => $q['command'] === '/file/remove'), 'params'), 'numbers');
        $this->assertSame(['routeros-7.24.6-arm.npk', 'wifi-qcom-ac-7.24.6-arm.npk'], $removed);
        $device->refresh();
        $this->assertSame(UpgradeStatus::Failed, $device->upgrade_status);
        $this->assertStringContainsString('wifi-qcom-ac-7.24.6-arm.npk', $device->upgrade_message);
    }

    public function test_an_upgrade_that_comes_back_on_the_old_version_fails_with_the_routers_reason(): void
    {
        $device = $this->routerOsDevice();
        $device->update(['arch' => 'arm', 'os_version' => '7.23.8']);
        $client = new FakeRouterOsClient(replies: [
            '/system/resource/print' => [['version' => '7.23.8 (stable)', 'architecture-name' => 'arm']],
            '/system/package/print' => [['name' => 'routeros']],
            '/file/print' => [['name' => 'routeros-7.24.6-arm.npk', 'size' => '13000000']],
            '/log/print' => [
                ['message' => 'missing package wifi-qcom-ac'],
                ['message' => 'router rebooted by api:admin@192.0.2.1/action:1'],
                ['message' => 'ether1 link up'],
            ],
        ]);

        // Rebooted fine and answers again - but RouterOS skipped the install.
        (new UpgradeDevice($client, new FakeRebootWaiter(result: true)))($device, '7.24.6', 'mikrotik');

        $device->refresh();
        $this->assertSame(UpgradeStatus::Failed, $device->upgrade_status);
        $this->assertSame('7.23.8', $device->os_version);
        $this->assertSame("Rebooted, but it's still on 7.23.8: RouterOS didn't install 7.24.6 (missing package wifi-qcom-ac).", $device->upgrade_message);
    }

    public function test_a_latest_channel_upgrade_that_comes_back_on_the_old_version_fails(): void
    {
        $device = $this->routerOsDevice();
        $client = new FakeRouterOsClient(replies: [
            '/system/package/update/print' => [['installed-version' => '7.20.7', 'latest-version' => '7.20.8']],
            '/system/resource/print' => [['version' => '7.20.7 (stable)']], // still the old one after the reboot
        ]);

        (new UpgradeDevice($client, new FakeRebootWaiter(result: true)))($device);

        $device->refresh();
        $this->assertSame(UpgradeStatus::Failed, $device->upgrade_status);
        $this->assertStringStartsWith("Rebooted, but it's still on 7.20.7: RouterOS didn't install 7.20.8", $device->upgrade_message);
    }

    public function test_mirror_upgrade_caches_and_serves_the_extra_packages_too(): void
    {
        Storage::fake('local');
        // A closure, so each download gets a fresh body (one shared response is drained by the first).
        Http::fake(['*download.mikrotik.com/*' => fn () => Http::response(str_repeat('N', 4096))]);
        $device = $this->routerOsDevice();
        $device->update(['arch' => 'arm64', 'os_version' => '7.23.8']);
        $client = new FakeRouterOsClient(replies: [
            '/system/resource/print' => [['version' => '7.23.8', 'architecture-name' => 'arm64']],
            '/system/package/print' => [['name' => 'routeros'], ['name' => 'wifi-qcom']],
            '/file/print' => [
                ['name' => 'routeros-7.24.6-arm64.npk', 'size' => '13000000'],
                ['name' => 'wifi-qcom-7.24.6-arm64.npk', 'size' => '3000000'],
            ],
        ]);

        (new UpgradeDevice($client, $this->comesBackOn($client, '7.24.6')))($device, '7.24.6', 'mirror');

        $cached = RouterosPackage::orderBy('id')->get();
        $this->assertSame(['routeros', 'wifi-qcom'], $cached->pluck('name')->all());
        $this->assertSame(['ready', 'ready'], $cached->pluck('status')->all());
        $this->assertSame($cached->map(fn (RouterosPackage $p): string => url('/rospkg/'.$p->token))->all(), $this->fetchedUrls($client));
        Http::assertSent(fn ($r): bool => str_ends_with($r->url(), '/7.24.6/wifi-qcom-7.24.6-arm64.npk'));
        $this->assertSame(UpgradeStatus::Done, $device->refresh()->upgrade_status);
    }

    public function test_a_long_failure_reason_is_cut_to_fit_rather_than_failing_the_write(): void
    {
        $device = $this->routerOsDevice();
        $device->update(['arch' => 'arm', 'os_version' => '7.23.8']);
        $client = new FakeRouterOsClient(replies: [
            '/system/resource/print' => [['version' => '7.23.8', 'architecture-name' => 'arm']],
            '/system/package/print' => [['name' => 'routeros']],
            '/file/print' => [['name' => 'routeros-7.24.6-arm.npk', 'size' => '13000000']],
            '/log/print' => [
                ['message' => 'missing package '.str_repeat('a', 120)],
                ['message' => 'missing package '.str_repeat('b', 120)],
            ],
        ]);

        (new UpgradeDevice($client, new FakeRebootWaiter(result: true)))($device, '7.24.6', 'mikrotik');

        $device->refresh();
        $this->assertSame(UpgradeStatus::Failed, $device->upgrade_status);
        $this->assertSame(255, mb_strlen($device->upgrade_message));
        $this->assertStringEndsWith('...', $device->upgrade_message);
        $this->assertSame($device->upgrade_message, $device->upgrades()->latest('id')->value('message'));
    }

    public function test_routeros_6_targeted_upgrade_stages_only_the_bundle(): void
    {
        $device = $this->routerOsDevice();
        $device->update(['arch' => 'mipsbe', 'os_version' => '6.49.10']);
        $client = new FakeRouterOsClient(replies: [
            '/system/resource/print' => [['version' => '6.49.10', 'architecture-name' => 'mipsbe']],
            // v6 lists the bundle's parts (system, wireless, ...) - they're all inside routeros.
            '/system/package/print' => [['name' => 'routeros-mipsbe'], ['name' => 'system'], ['name' => 'wireless']],
            '/file/print' => [['name' => 'routeros-mipsbe-6.49.18.npk', 'size' => '12000000']],
        ]);

        (new UpgradeDevice($client, new FakeRebootWaiter(result: true)))($device, '6.49.18', 'mikrotik');

        $this->assertSame(['https://download.mikrotik.com/routeros/6.49.18/routeros-mipsbe-6.49.18.npk'], $this->fetchedUrls($client));
        $this->assertNotContains('/system/package/print', $client->opened[0]->commands());
    }

    public function test_targeted_upgrade_does_not_reboot_if_the_package_never_landed(): void
    {
        $device = $this->routerOsDevice();
        $device->update(['arch' => 'arm', 'os_version' => '7.20.7']);
        $client = new FakeRouterOsClient(replies: [
            '/system/resource/print' => [['version' => '7.20.7', 'architecture-name' => 'arm']],
            '/file/print' => [], // fetch failed - nothing on the device
        ]);

        (new UpgradeDevice($client, new FakeRebootWaiter(result: true)))($device, '7.20.9', 'mikrotik');

        $this->assertNotContains('/system/reboot', $client->opened[0]->commands());
        $this->assertSame(UpgradeStatus::Failed, $device->refresh()->upgrade_status);
    }

    public function test_targeted_upgrade_is_a_noop_when_already_on_that_version(): void
    {
        $device = $this->routerOsDevice();
        $device->update(['arch' => 'arm']);
        $client = new FakeRouterOsClient(replies: [
            '/system/resource/print' => [['version' => '7.20.9', 'architecture-name' => 'arm']],
        ]);

        (new UpgradeDevice($client, new FakeRebootWaiter(result: true)))($device, '7.20.9', 'mikrotik');

        $this->assertNotContains('/system/reboot', $client->opened[0]->commands());
        $this->assertSame(UpgradeStatus::UpToDate, $device->refresh()->upgrade_status);
    }

    public function test_marks_up_to_date_when_already_on_the_latest(): void
    {
        $device = $this->routerOsDevice();
        $client = new FakeRouterOsClient(replies: [
            '/system/package/update/print' => [['installed-version' => '7.20.8', 'latest-version' => '7.20.8']],
        ]);

        (new UpgradeDevice($client, new FakeRebootWaiter))($device);

        $this->assertNotContains('/system/reboot', $client->opened[0]->commands()); // nothing to do
        $device->refresh();
        $this->assertSame(UpgradeStatus::UpToDate, $device->upgrade_status);
        $this->assertSame('7.20.8', $device->os_version);
        $this->assertSame([], (new FakeRebootWaiter)->awaited); // (no wait happened)
    }

    public function test_marks_failed_when_the_device_never_comes_back(): void
    {
        $device = $this->routerOsDevice();

        (new UpgradeDevice($this->clientWithUpdate(), new FakeRebootWaiter(result: false)))($device);

        $device->refresh();
        $this->assertSame(UpgradeStatus::Failed, $device->upgrade_status);
        $this->assertStringContainsString('come back', (string) $device->upgrade_message);
    }

    public function test_rejects_a_non_routeros_device_and_marks_failed(): void
    {
        $device = Device::factory()->create(['poll_method' => PollMethod::Snmp]);
        $client = new FakeRouterOsClient;

        try {
            (new UpgradeDevice($client, new FakeRebootWaiter))($device);
            $this->fail('expected RouterOsClientException');
        } catch (RouterOsClientException) {
            $this->assertSame([], $client->opened); // never reached the device
            $this->assertSame(UpgradeStatus::Failed, $device->fresh()->upgrade_status);
        }
    }

    public function test_a_filtered_port_fails_fast_and_marks_failed(): void
    {
        $client = new FakeRouterOsClient(failOpenWith: new RouterOsClientException('connect timeout'));
        $device = $this->routerOsDevice();

        try {
            (new UpgradeDevice($client, new FakeRebootWaiter))($device);
            $this->fail('expected RouterOsClientException');
        } catch (RouterOsClientException) {
            $this->assertSame(UpgradeStatus::Failed, $device->fresh()->upgrade_status);
        }
    }

    public function test_skips_a_down_device(): void
    {
        $device = $this->routerOsDevice();
        $device->update(['status' => DeviceStatus::Down]);
        $client = new FakeRouterOsClient;

        (new UpgradeDevice($client, new FakeRebootWaiter))($device);

        $this->assertSame([], $client->opened); // never connected to a down device
        $this->assertSame(UpgradeStatus::Failed, $device->fresh()->upgrade_status);
        $this->assertStringContainsString('down', (string) $device->fresh()->upgrade_message);
    }

    public function test_skips_when_the_parent_is_down(): void
    {
        $parent = $this->routerOsDevice();
        $parent->update(['status' => DeviceStatus::Down]);
        $child = $this->routerOsDevice();
        $child->update(['parent_device_id' => $parent->id]);
        $client = new FakeRouterOsClient;

        (new UpgradeDevice($client, new FakeRebootWaiter))($child);

        $this->assertSame([], $client->opened); // path unreachable - never connected
        $this->assertSame(UpgradeStatus::Failed, $child->fresh()->upgrade_status);
        $this->assertStringContainsString('Parent', (string) $child->fresh()->upgrade_message);
    }

    /**
     * Adversarial-review finding (2026-07-01): a manual single-device upgrade and a
     * bulk upgrade selecting the same device previously had no shared lock (Job-level
     * WithoutOverlapping is scoped per-job-class, so RunBulkUpgrade's direct Action
     * call never coordinated with UpgradeDeviceJob's). The Action-level lock closes
     * that regardless of entry point.
     */
    public function test_refuses_to_start_a_second_concurrent_upgrade_of_the_same_device(): void
    {
        $device = $this->routerOsDevice();
        $lock = Cache::lock("device-upgrade-inflight:{$device->id}", 900);
        $this->assertTrue($lock->get(), 'test setup: failed to pre-acquire the lock');

        $client = new FakeRouterOsClient;

        try {
            (new UpgradeDevice($client, new FakeRebootWaiter))($device);
            $this->fail('expected a RuntimeException when another upgrade is already in progress');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already has an upgrade in progress', $e->getMessage());
        } finally {
            $lock->release();
        }

        // Never even connected to the device - refused before any RouterOS command.
        $this->assertSame([], $client->targets);
    }

    public function test_preflight_reports_skips_without_executing(): void
    {
        Queue::fake();
        $up = $this->routerOsDevice();
        $down = $this->routerOsDevice();
        $down->update(['status' => DeviceStatus::Down]);
        $snmp = Device::factory()->create(['poll_method' => PollMethod::Snmp]);

        $res = $this->postJson('/api/devices/upgrade/preflight', ['device_ids' => [$up->id, $down->id, $snmp->id]])
            ->assertOk()
            ->assertJsonCount(3, 'plan');

        $plan = collect($res->json('plan'))->keyBy('device_id');
        $this->assertSame('upgrade', $plan[$up->id]['action']);
        $this->assertSame('skip', $plan[$down->id]['action']);
        $this->assertSame('skip', $plan[$snmp->id]['action']);
        $this->assertContains($up->id, $res->json('upgrade'));

        // Dry-run only: nothing queued, no status mutated.
        Queue::assertNothingPushed();
        $this->assertNull($up->fresh()->upgrade_status);
    }

    public function test_endpoint_queues_a_job_per_device_and_marks_queued(): void
    {
        Queue::fake();
        $a = $this->routerOsDevice();
        $b = Device::factory()->create();

        $this->postJson('/api/devices/upgrade', ['device_ids' => [$a->id, $b->id]])
            ->assertStatus(202)
            ->assertJsonPath('queued', 2);

        Queue::assertPushed(UpgradeDeviceJob::class, 2);
        Queue::assertPushed(fn (UpgradeDeviceJob $job) => $job->queue === 'upgrade');
        $this->assertSame(UpgradeStatus::Queued, $a->fresh()->upgrade_status); // spinner shows immediately
    }

    public function test_endpoint_ordered_dispatches_a_single_bulk_job(): void
    {
        Queue::fake();
        $a = $this->routerOsDevice();
        $b = $this->routerOsDevice();

        $this->postJson('/api/devices/upgrade', ['device_ids' => [$a->id, $b->id], 'ordered' => true])
            ->assertStatus(202)
            ->assertJsonPath('ordered', true);

        Queue::assertPushed(BulkUpgradeJob::class, 1);
        Queue::assertNotPushed(UpgradeDeviceJob::class);
    }

    public function test_endpoint_validates_device_ids(): void
    {
        Queue::fake();

        $this->postJson('/api/devices/upgrade', ['device_ids' => []])
            ->assertStatus(422)->assertJsonValidationErrors(['device_ids']);

        $this->postJson('/api/devices/upgrade', ['device_ids' => [999999]])
            ->assertStatus(422)->assertJsonValidationErrors(['device_ids.0']);

        Queue::assertNothingPushed();
    }
}
