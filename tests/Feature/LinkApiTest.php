<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Link;
use App\Models\NetworkInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LinkApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsUser();
    }

    /** @return array{0: Device, 1: Device, 2: NetworkInterface, 3: NetworkInterface} */
    private function twoLinkableDevices(): array
    {
        $a = Device::factory()->create();
        $b = Device::factory()->create();
        $aIf = NetworkInterface::factory()->for($a)->create(['speed_mbps' => 1000, 'util_in' => 12.5, 'util_out' => 3.0]);
        $bIf = NetworkInterface::factory()->for($b)->create(['speed_mbps' => 10000, 'util_in' => 1.2, 'util_out' => 0.4]);

        return [$a, $b, $aIf, $bIf];
    }

    public function test_creates_a_link_and_returns_interface_ids_with_current_util(): void
    {
        [$a, $b, $aIf, $bIf] = $this->twoLinkableDevices();

        $this->postJson('/api/links', [
            'a_device_id' => $a->id, 'a_interface_id' => $aIf->id,
            'b_device_id' => $b->id, 'b_interface_id' => $bIf->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.a_interface_id', $aIf->id)
            ->assertJsonPath('data.b_interface_id', $bIf->id)
            ->assertJsonPath('data.a_interface.util_in', 12.5)   // edges colour on load, no flash of grey
            ->assertJsonPath('data.b_interface.speed_mbps', 10000);

        $this->assertDatabaseHas('links', ['a_interface_id' => $aIf->id, 'b_interface_id' => $bIf->id]);
    }

    public function test_index_lists_links_with_both_interfaces(): void
    {
        [$a, $b, $aIf, $bIf] = $this->twoLinkableDevices();
        Link::create(['a_device_id' => $a->id, 'a_interface_id' => $aIf->id, 'b_device_id' => $b->id, 'b_interface_id' => $bIf->id]);

        $this->getJson('/api/links')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.a_interface.name', $aIf->name);
    }

    public function test_rejects_interface_that_does_not_belong_to_its_device(): void
    {
        [$a, $b, $aIf, $bIf] = $this->twoLinkableDevices();

        // a_interface_id is B's interface - doesn't belong to a_device.
        $this->postJson('/api/links', [
            'a_device_id' => $a->id, 'a_interface_id' => $bIf->id,
            'b_device_id' => $b->id, 'b_interface_id' => $bIf->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['a_interface_id']);
    }

    public function test_rejects_linking_an_interface_to_itself(): void
    {
        [$a, $b, $aIf] = $this->twoLinkableDevices();

        $this->postJson('/api/links', [
            'a_device_id' => $a->id, 'a_interface_id' => $aIf->id,
            'b_device_id' => $a->id, 'b_interface_id' => $aIf->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['a_interface_id']);
    }

    public function test_rejects_duplicate_link_in_either_direction(): void
    {
        [$a, $b, $aIf, $bIf] = $this->twoLinkableDevices();
        Link::create(['a_device_id' => $a->id, 'a_interface_id' => $aIf->id, 'b_device_id' => $b->id, 'b_interface_id' => $bIf->id]);

        // Same pair, reversed - must be rejected as a duplicate (not a 500 from the unique index).
        $this->postJson('/api/links', [
            'a_device_id' => $b->id, 'a_interface_id' => $bIf->id,
            'b_device_id' => $a->id, 'b_interface_id' => $aIf->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['a_interface_id']);

        $this->assertSame(1, Link::count());
    }

    public function test_updates_a_link_rebinding_an_end_and_returns_new_util(): void
    {
        [$a, $b, $aIf, $bIf] = $this->twoLinkableDevices();
        $link = Link::create(['a_device_id' => $a->id, 'a_interface_id' => $aIf->id, 'b_device_id' => $b->id, 'b_interface_id' => $bIf->id]);

        // A second interface on B to re-bind the B end to.
        $bIf2 = NetworkInterface::factory()->for($b)->create(['speed_mbps' => 2500, 'util_in' => 9.9, 'util_out' => 1.1]);

        $this->putJson("/api/links/{$link->id}", [
            'a_device_id' => $a->id, 'a_interface_id' => $aIf->id,
            'b_device_id' => $b->id, 'b_interface_id' => $bIf2->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.b_interface_id', $bIf2->id)
            ->assertJsonPath('data.b_interface.util_in', 9.9)        // edge recolours to the new end
            ->assertJsonPath('data.b_interface.speed_mbps', 2500);

        $this->assertDatabaseHas('links', ['id' => $link->id, 'b_interface_id' => $bIf2->id]);
        $this->assertDatabaseMissing('links', ['id' => $link->id, 'b_interface_id' => $bIf->id]);
    }

    public function test_update_allows_resaving_the_same_link_unchanged(): void
    {
        [$a, $b, $aIf, $bIf] = $this->twoLinkableDevices();
        $link = Link::create(['a_device_id' => $a->id, 'a_interface_id' => $aIf->id, 'b_device_id' => $b->id, 'b_interface_id' => $bIf->id]);

        // The duplicate check must exclude the row being edited - saving it as-is is not a "duplicate".
        $this->putJson("/api/links/{$link->id}", [
            'a_device_id' => $a->id, 'a_interface_id' => $aIf->id,
            'b_device_id' => $b->id, 'b_interface_id' => $bIf->id,
        ])->assertOk();
    }

    public function test_update_rejects_a_duplicate_of_another_link_in_either_direction(): void
    {
        [$a, $b, $aIf, $bIf] = $this->twoLinkableDevices();
        $aIf2 = NetworkInterface::factory()->for($a)->create();
        // Link #1: aIf <-> bIf. Link #2: aIf2 <-> bIf (the one we'll edit).
        Link::create(['a_device_id' => $a->id, 'a_interface_id' => $aIf->id, 'b_device_id' => $b->id, 'b_interface_id' => $bIf->id]);
        $link2 = Link::create(['a_device_id' => $a->id, 'a_interface_id' => $aIf2->id, 'b_device_id' => $b->id, 'b_interface_id' => $bIf->id]);

        // Editing #2 to (bIf, aIf) reversed collides with #1 - must 422, not a 500 from the unique index.
        $this->putJson("/api/links/{$link2->id}", [
            'a_device_id' => $b->id, 'a_interface_id' => $bIf->id,
            'b_device_id' => $a->id, 'b_interface_id' => $aIf->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['a_interface_id']);
    }

    public function test_update_rejects_interface_that_does_not_belong_to_its_device(): void
    {
        [$a, $b, $aIf, $bIf] = $this->twoLinkableDevices();
        $link = Link::create(['a_device_id' => $a->id, 'a_interface_id' => $aIf->id, 'b_device_id' => $b->id, 'b_interface_id' => $bIf->id]);

        // a_interface_id is B's interface - doesn't belong to a_device.
        $this->putJson("/api/links/{$link->id}", [
            'a_device_id' => $a->id, 'a_interface_id' => $bIf->id,
            'b_device_id' => $b->id, 'b_interface_id' => $bIf->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['a_interface_id']);
    }

    public function test_link_effective_speed_defaults_to_the_slowest_end(): void
    {
        // aIf=1000, bIf=10000 -> derived link speed (both directions) = the slower, 1000.
        [$a, $b, $aIf, $bIf] = $this->twoLinkableDevices();

        $this->postJson('/api/links', [
            'a_device_id' => $a->id, 'a_interface_id' => $aIf->id,
            'b_device_id' => $b->id, 'b_interface_id' => $bIf->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.bw_ab_mbps', null)
            ->assertJsonPath('data.eff_ab_mbps', 1000)
            ->assertJsonPath('data.eff_ba_mbps', 1000);
    }

    public function test_sets_and_reverts_an_asymmetric_link_bandwidth_override(): void
    {
        // A 500dn/50up circuit on the link - independent of the (1000/10000) port speeds.
        [$a, $b, $aIf, $bIf] = $this->twoLinkableDevices();
        $link = Link::create(['a_device_id' => $a->id, 'a_interface_id' => $aIf->id, 'b_device_id' => $b->id, 'b_interface_id' => $bIf->id]);

        $this->putJson("/api/links/{$link->id}", [
            'a_device_id' => $a->id, 'a_interface_id' => $aIf->id,
            'b_device_id' => $b->id, 'b_interface_id' => $bIf->id,
            'bw_ab_mbps' => 500, 'bw_ba_mbps' => 50,
        ])
            ->assertOk()
            ->assertJsonPath('data.eff_ab_mbps', 500)
            ->assertJsonPath('data.eff_ba_mbps', 50);

        $this->assertDatabaseHas('links', ['id' => $link->id, 'bw_ab_mbps' => 500, 'bw_ba_mbps' => 50]);

        // Revert both -> effective speed falls back to the slowest end again (1000).
        $this->putJson("/api/links/{$link->id}", [
            'a_device_id' => $a->id, 'a_interface_id' => $aIf->id,
            'b_device_id' => $b->id, 'b_interface_id' => $bIf->id,
            'bw_ab_mbps' => null, 'bw_ba_mbps' => null,
        ])
            ->assertOk()
            ->assertJsonPath('data.eff_ab_mbps', 1000)
            ->assertJsonPath('data.eff_ba_mbps', 1000);
    }

    public function test_ba_override_falls_back_to_ab_when_only_ab_is_set(): void
    {
        // A symmetric circuit only needs bw_ab - bw_ba inherits it.
        [$a, $b, $aIf, $bIf] = $this->twoLinkableDevices();
        $link = Link::create(['a_device_id' => $a->id, 'a_interface_id' => $aIf->id, 'b_device_id' => $b->id, 'b_interface_id' => $bIf->id]);

        $this->putJson("/api/links/{$link->id}", [
            'a_device_id' => $a->id, 'a_interface_id' => $aIf->id,
            'b_device_id' => $b->id, 'b_interface_id' => $bIf->id,
            'bw_ab_mbps' => 300,
        ])
            ->assertOk()
            ->assertJsonPath('data.eff_ab_mbps', 300)
            ->assertJsonPath('data.eff_ba_mbps', 300);
    }

    public function test_rejects_a_zero_link_bandwidth(): void
    {
        [$a, $b, $aIf, $bIf] = $this->twoLinkableDevices();
        $link = Link::create(['a_device_id' => $a->id, 'a_interface_id' => $aIf->id, 'b_device_id' => $b->id, 'b_interface_id' => $bIf->id]);

        $this->putJson("/api/links/{$link->id}", [
            'a_device_id' => $a->id, 'a_interface_id' => $aIf->id,
            'b_device_id' => $b->id, 'b_interface_id' => $bIf->id,
            'bw_ab_mbps' => 0,
        ])->assertStatus(422)->assertJsonValidationErrors(['bw_ab_mbps']);
    }

    public function test_link_util_is_computed_against_the_effective_speed(): void
    {
        // CPE upload 20 Mbps on a 50 Mbps uplink (override) -> util_ba ~ 40%.
        $a = Device::factory()->create();
        $b = Device::factory()->create();
        $aIf = NetworkInterface::factory()->for($a)->create(['speed_mbps' => 1000, 'bps_out' => 120_000_000]);
        $bIf = NetworkInterface::factory()->for($b)->create(['speed_mbps' => 1000, 'bps_out' => 20_000_000]);
        $link = Link::create([
            'a_device_id' => $a->id, 'a_interface_id' => $aIf->id,
            'b_device_id' => $b->id, 'b_interface_id' => $bIf->id,
            'bw_ab_mbps' => 500, 'bw_ba_mbps' => 50,
        ])->load('aInterface', 'bInterface');

        $this->assertEqualsWithDelta(24.0, $link->utilAb(), 0.01);  // 120M / 500M
        $this->assertEqualsWithDelta(40.0, $link->utilBa(), 0.01);  // 20M / 50M
        $this->assertEqualsWithDelta(40.0, $link->util(), 0.01);    // busier direction
    }

    public function test_deletes_a_link(): void
    {
        [$a, $b, $aIf, $bIf] = $this->twoLinkableDevices();
        $link = Link::create(['a_device_id' => $a->id, 'a_interface_id' => $aIf->id, 'b_device_id' => $b->id, 'b_interface_id' => $bIf->id]);

        $this->deleteJson("/api/links/{$link->id}")->assertNoContent();
        $this->assertDatabaseMissing('links', ['id' => $link->id]);
    }

    public function test_creates_a_one_ended_link_to_a_ping_only_device(): void
    {
        // A ping-only device (poll_method=none) has no interfaces - it links from the
        // real end only, so b_interface_id is null and the link speed comes from A.
        [$a, $b, $aIf] = $this->twoLinkableDevices();
        $b->update(['poll_method' => 'none']);

        $this->postJson('/api/links', [
            'a_device_id' => $a->id, 'a_interface_id' => $aIf->id,
            'b_device_id' => $b->id, 'b_interface_id' => null,
        ])
            ->assertCreated()
            ->assertJsonPath('data.a_interface_id', $aIf->id)
            ->assertJsonPath('data.b_interface_id', null)
            ->assertJsonPath('data.b_interface', null)
            ->assertJsonPath('data.eff_ab_mbps', 1000); // derived from the single (A) end

        $this->assertDatabaseHas('links', ['a_interface_id' => $aIf->id, 'b_interface_id' => null]);
    }

    public function test_rejects_a_duplicate_one_ended_link_in_either_direction(): void
    {
        [$a, $b, $aIf] = $this->twoLinkableDevices();
        $b->update(['poll_method' => 'none']);
        Link::create(['a_device_id' => $a->id, 'a_interface_id' => $aIf->id, 'b_device_id' => $b->id, 'b_interface_id' => null]);

        // Same devices + the same null end, reversed - a duplicate, not a second link.
        $this->postJson('/api/links', [
            'a_device_id' => $b->id, 'a_interface_id' => null,
            'b_device_id' => $a->id, 'b_interface_id' => $aIf->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['a_interface_id']);

        $this->assertSame(1, Link::count());
    }

    public function test_lists_a_devices_interfaces_for_the_binder(): void
    {
        $device = Device::factory()->create();
        NetworkInterface::factory()->for($device)->create(['if_index' => 1, 'name' => 'ether1', 'description' => 'Uplink to core']);
        NetworkInterface::factory()->for($device)->create(['if_index' => 2, 'name' => 'ether2']);
        NetworkInterface::factory()->create(); // another device's interface - must not appear

        $this->getJson("/api/devices/{$device->id}/interfaces")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'ether1')
            ->assertJsonPath('data.0.description', 'Uplink to core'); // shown as sub-text in the binder picker
    }
}
