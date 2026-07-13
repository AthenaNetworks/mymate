<?php

namespace Tests\Feature;

use App\Actions\Devices\UpgradePreflight;
use App\Enums\PollMethod;
use App\Jobs\BulkUpgradeJob;
use App\Models\Credential;
use App\Models\Device;
use App\Models\Link;
use App\Support\DeviceHierarchy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class UpgradePreflightTest extends TestCase
{
    use RefreshDatabase;

    private Credential $cred;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cred = Credential::factory()->routeros()->create();
    }

    private function device(string $name, ?int $parentId = null): Device
    {
        return Device::factory()->create([
            'name' => $name,
            'parent_device_id' => $parentId,
            'poll_method' => PollMethod::RouterOs,
            'credential_id' => $this->cred->id,
            'os_version' => '7.20.7',
            'latest_version' => '7.20.8',
        ]);
    }

    private function preflight(): UpgradePreflight
    {
        return new UpgradePreflight(new DeviceHierarchy);
    }

    public function test_default_order_is_furthest_downstream_first_with_depth(): void
    {
        $core = $this->device('core');
        $dist = $this->device('dist', $core->id);
        $leaf = $this->device('leaf', $dist->id);

        // Selection in arbitrary order -> ordered leaf, dist, core (deepest first).
        $result = ($this->preflight())([$core->id, $leaf->id, $dist->id]);

        $this->assertSame([$leaf->id, $dist->id, $core->id], $result['order']);
        $this->assertSame(2, $result['plan'][0]['depth']); // leaf is 2 hops from root
        $this->assertSame('dist', $result['plan'][0]['parent_name']); // leaf's parent is dist
    }

    public function test_preserve_order_keeps_the_given_sequence(): void
    {
        $core = $this->device('core');
        $dist = $this->device('dist', $core->id);
        $leaf = $this->device('leaf', $dist->id);

        // Operator's hand-picked order (core first) must be kept verbatim.
        $result = ($this->preflight())([$core->id, $dist->id, $leaf->id], preserveOrder: true);

        $this->assertSame([$core->id, $dist->id, $leaf->id], $result['order']);
    }

    public function test_enriches_rows_with_linked_neighbours(): void
    {
        $a = $this->device('rtr-a');
        $b = $this->device('rtr-b');
        // A link between them (interface ends null - the neighbour list only needs devices).
        Link::create(['a_device_id' => $a->id, 'a_interface_id' => null, 'b_device_id' => $b->id, 'b_interface_id' => null]);

        $result = ($this->preflight())([$a->id, $b->id]);
        $rowA = collect($result['plan'])->firstWhere('device_id', $a->id);

        $this->assertSame(['rtr-b'], $rowA['neighbours']);
    }

    public function test_endpoint_with_explicit_order_dispatches_a_bulk_job_that_preserves_order(): void
    {
        $this->actingAsUser();
        Queue::fake();
        $core = $this->device('core');
        $leaf = $this->device('leaf', $core->id);

        $this->postJson('/api/devices/upgrade', [
            'device_ids' => [$core->id, $leaf->id], // core first (operator's manual order)
            'ordered' => true,
            'explicit_order' => true,
        ])->assertAccepted();

        Queue::assertPushed(BulkUpgradeJob::class, fn (BulkUpgradeJob $j) => $j->preserveOrder === true
            && $j->deviceIds === [$core->id, $leaf->id]);
    }
}
