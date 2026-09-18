<?php

namespace Tests\Feature;

use App\Enums\DeviceType;
use App\Models\Credential;
use App\Models\Device;
use App\Models\DeviceMapPosition;
use App\Models\Link;
use App\Models\Map;
use App\Models\NetworkInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsUser();
    }

    public function test_it_lists_devices(): void
    {
        Device::factory()->count(3)->create();

        $this->getJson('/api/devices')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure(['data' => [['id', 'name', 'mgmt_ip', 'poll_method', 'status', 'map_x', 'map_y']]]);
    }

    public function test_it_creates_a_device_with_default_unknown_status(): void
    {
        $this->postJson('/api/devices', ['name' => 'Edge1', 'mgmt_ip' => '10.0.0.1', 'poll_method' => 'snmp'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Edge1')
            ->assertJsonPath('data.status', 'unknown');

        $this->assertDatabaseHas('devices', ['name' => 'Edge1', 'mgmt_ip' => '10.0.0.1', 'status' => 'unknown']);
    }

    public function test_it_places_a_new_device_on_the_default_map_by_default(): void
    {
        $id = $this->postJson('/api/devices', ['name' => 'Edge1', 'mgmt_ip' => '10.0.0.1', 'poll_method' => 'snmp'])
            ->assertCreated()->json('data.id');

        $this->assertDatabaseHas('device_map_positions', ['device_id' => $id, 'map_id' => Map::default()->id]);
    }

    public function test_it_creates_a_device_without_placing_it_on_a_map(): void
    {
        $id = $this->postJson('/api/devices', [
            'name' => 'Client-CPE', 'mgmt_ip' => '10.0.0.2', 'poll_method' => 'none', 'place_on_map' => false,
        ])->assertCreated()->json('data.id');

        $this->assertDatabaseHas('devices', ['id' => $id]);
        $this->assertDatabaseMissing('device_map_positions', ['device_id' => $id]);
    }

    public function test_it_removes_a_device_from_every_map(): void
    {
        $device = Device::factory()->create();
        $other = Device::factory()->create();
        foreach (Map::factory()->count(2)->create() as $map) {
            DeviceMapPosition::create(['device_id' => $device->id, 'map_id' => $map->id, 'x' => 1, 'y' => 2]);
            DeviceMapPosition::create(['device_id' => $other->id, 'map_id' => $map->id, 'x' => 3, 'y' => 4]);
        }

        $this->deleteJson("/api/devices/{$device->id}/map-positions")->assertNoContent();

        $this->assertDatabaseMissing('device_map_positions', ['device_id' => $device->id]);
        $this->assertDatabaseHas('devices', ['id' => $device->id]);                  // still monitored
        $this->assertSame(2, DeviceMapPosition::where('device_id', $other->id)->count()); // untouched
    }

    public function test_index_and_show_report_how_many_maps_a_device_is_on(): void
    {
        $placed = Device::factory()->create(['name' => 'placed']);
        $hidden = Device::factory()->create(['name' => 'hidden']);
        foreach (Map::factory()->count(2)->create() as $map) {
            DeviceMapPosition::create(['device_id' => $placed->id, 'map_id' => $map->id, 'x' => 0, 'y' => 0]);
        }

        $rows = collect($this->getJson('/api/devices')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame(2, $rows[$placed->id]['maps_count']);
        $this->assertSame(0, $rows[$hidden->id]['maps_count']);

        $this->getJson("/api/devices/{$placed->id}")->assertOk()->assertJsonPath('data.maps_count', 2);
    }

    public function test_it_validates_on_create(): void
    {
        $this->postJson('/api/devices', ['name' => '', 'mgmt_ip' => 'not-an-ip', 'poll_method' => 'bogus'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'mgmt_ip', 'poll_method']);
    }

    public function test_it_shows_a_device(): void
    {
        $device = Device::factory()->create();

        $this->getJson("/api/devices/{$device->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $device->id);
    }

    public function test_it_updates_a_device(): void
    {
        $device = Device::factory()->create(['name' => 'Old']);

        $this->putJson("/api/devices/{$device->id}", ['name' => 'New'])
            ->assertOk()
            ->assertJsonPath('data.name', 'New');

        $this->assertDatabaseHas('devices', ['id' => $device->id, 'name' => 'New']);
    }

    public function test_it_attaches_a_dedicated_ssh_credential(): void
    {
        $device = Device::factory()->create();
        $ssh = \App\Models\Credential::factory()->ssh()->create();

        $this->putJson("/api/devices/{$device->id}", ['ssh_credential_id' => $ssh->id])
            ->assertOk()
            ->assertJsonPath('data.ssh_credential_id', $ssh->id);

        $this->assertDatabaseHas('devices', ['id' => $device->id, 'ssh_credential_id' => $ssh->id]);
    }

    public function test_it_toggles_monitoring(): void
    {
        $device = Device::factory()->create(['monitored' => true]);

        $this->putJson("/api/devices/{$device->id}", ['monitored' => false])
            ->assertOk()
            ->assertJsonPath('data.monitored', false);

        $this->assertDatabaseHas('devices', ['id' => $device->id, 'monitored' => false]);
    }

    public function test_it_persists_position(): void
    {
        $device = Device::factory()->create(['map_x' => 0, 'map_y' => 0]);

        $this->patchJson("/api/devices/{$device->id}/position", ['map_x' => 120.5, 'map_y' => -33.25])
            ->assertOk()
            ->assertJsonPath('data.map_x', 120.5)
            ->assertJsonPath('data.map_y', -33.25);

        $this->assertDatabaseHas('devices', ['id' => $device->id, 'map_x' => 120.5, 'map_y' => -33.25]);
    }

    public function test_it_deletes_a_device(): void
    {
        $device = Device::factory()->create();

        $this->deleteJson("/api/devices/{$device->id}")->assertNoContent();

        $this->assertDatabaseMissing('devices', ['id' => $device->id]);
    }

    public function test_it_never_exposes_credential_secrets(): void
    {
        $credential = Credential::create([
            'name' => 'Shared SNMP',
            'type' => 'snmp',
            'snmp_community' => 'supersecret-community',
            'api_port' => 8728,
        ]);
        $device = Device::factory()->create(['credential_id' => $credential->id]);

        $json = $this->getJson("/api/devices/{$device->id}")->assertOk()->getContent();

        $this->assertStringNotContainsString('supersecret-community', $json);
        $this->assertStringNotContainsString('snmp_community', $json);
    }

    public function test_it_creates_a_device_with_type_and_parent(): void
    {
        $parent = Device::factory()->create();

        $this->postJson('/api/devices', [
            'name' => 'Edge2', 'mgmt_ip' => '10.0.0.2', 'poll_method' => 'routeros',
            'device_type' => 'switch', 'parent_device_id' => $parent->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.device_type', 'switch')
            ->assertJsonPath('data.parent_device_id', $parent->id)
            ->assertJsonPath('data.parent_name', $parent->name);

        $this->assertDatabaseHas('devices', [
            'name' => 'Edge2', 'device_type' => 'switch', 'parent_device_id' => $parent->id,
        ]);
    }

    public function test_resource_exposes_metadata_fields(): void
    {
        $device = Device::factory()->create([
            'device_type' => DeviceType::Router, 'vendor' => 'MikroTik', 'model' => 'CCR2004', 'uptime_seconds' => 3600,
        ]);

        $this->getJson("/api/devices/{$device->id}")
            ->assertOk()
            ->assertJsonPath('data.device_type', 'router')
            ->assertJsonPath('data.vendor', 'MikroTik')
            ->assertJsonPath('data.model', 'CCR2004')
            ->assertJsonPath('data.uptime_seconds', 3600);
    }

    public function test_it_updates_and_exposes_latency_quality_thresholds(): void
    {
        $device = Device::factory()->create(['device_type' => DeviceType::Internet, 'poll_method' => 'none']);

        $this->patchJson("/api/devices/{$device->id}", ['latency_good_ms' => 25, 'latency_bad_ms' => 200])
            ->assertOk()
            ->assertJsonPath('data.latency_good_ms', 25)
            ->assertJsonPath('data.latency_bad_ms', 200);

        $this->assertDatabaseHas('devices', ['id' => $device->id, 'latency_good_ms' => 25, 'latency_bad_ms' => 200]);
    }

    public function test_it_rejects_a_negative_latency_threshold(): void
    {
        $device = Device::factory()->create();

        $this->patchJson("/api/devices/{$device->id}", ['latency_bad_ms' => -5])
            ->assertJsonValidationErrors('latency_bad_ms');
    }

    public function test_it_defaults_device_type_to_unknown(): void
    {
        $this->postJson('/api/devices', ['name' => 'Plain', 'mgmt_ip' => '10.0.0.9', 'poll_method' => 'snmp'])
            ->assertCreated()
            ->assertJsonPath('data.device_type', 'unknown');
    }

    public function test_it_rejects_an_invalid_device_type(): void
    {
        $device = Device::factory()->create();

        $this->putJson("/api/devices/{$device->id}", ['device_type' => 'toaster'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['device_type']);
    }

    public function test_a_device_cannot_be_its_own_parent(): void
    {
        $device = Device::factory()->create();

        $this->putJson("/api/devices/{$device->id}", ['parent_device_id' => $device->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['parent_device_id']);
    }

    public function test_it_sets_changes_and_clears_a_parent(): void
    {
        // GitHub #45 - the map's node menu / inspector re-home a device by PATCHing this one field.
        $core = Device::factory()->create(['name' => 'Core']);
        $edge = Device::factory()->create(['name' => 'Edge']);
        $device = Device::factory()->create(['parent_device_id' => null]);

        $this->putJson("/api/devices/{$device->id}", ['parent_device_id' => $core->id])
            ->assertOk()
            ->assertJsonPath('data.parent_device_id', $core->id)
            ->assertJsonPath('data.parent_name', 'Core'); // the inspector reads the name from here

        $this->putJson("/api/devices/{$device->id}", ['parent_device_id' => $edge->id])
            ->assertOk()
            ->assertJsonPath('data.parent_device_id', $edge->id)
            ->assertJsonPath('data.parent_name', 'Edge');

        $this->putJson("/api/devices/{$device->id}", ['parent_device_id' => null])
            ->assertOk()
            ->assertJsonPath('data.parent_device_id', null)
            ->assertJsonPath('data.parent_name', null);

        $this->assertDatabaseHas('devices', ['id' => $device->id, 'parent_device_id' => null]);
    }

    public function test_a_device_cannot_be_parented_to_its_own_descendant(): void
    {
        // GitHub #45 - core <- edge <- cpe. Parenting the core to any of its downstream gear
        // closes a loop, which quietly breaks alert suppression / upgrade ordering / geo
        // inheritance, so it's refused (NotADeviceDescendant).
        $core = Device::factory()->create(['name' => 'Core']);
        $edge = Device::factory()->create(['name' => 'Edge', 'parent_device_id' => $core->id]);
        $cpe = Device::factory()->create(['name' => 'CPE', 'parent_device_id' => $edge->id]);

        $this->putJson("/api/devices/{$core->id}", ['parent_device_id' => $edge->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['parent_device_id']);

        // ...including a grandchild, not just the immediate one.
        $this->putJson("/api/devices/{$core->id}", ['parent_device_id' => $cpe->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['parent_device_id']);

        $this->assertDatabaseHas('devices', ['id' => $core->id, 'parent_device_id' => null]);

        // A sibling / unrelated device is still a perfectly good parent.
        $other = Device::factory()->create(['name' => 'Other']);
        $this->putJson("/api/devices/{$core->id}", ['parent_device_id' => $other->id])->assertOk();
    }

    public function test_a_parent_loop_already_in_the_data_does_not_hang_validation(): void
    {
        // Imported data can carry a loop the rule never saw (it only blocks *new* ones). The
        // walk is cycle-guarded, so validating against it terminates instead of spinning.
        $a = Device::factory()->create();
        $b = Device::factory()->create(['parent_device_id' => $a->id]);
        Device::whereKey($a->id)->update(['parent_device_id' => $b->id]); // a <-> b

        $c = Device::factory()->create();

        $this->putJson("/api/devices/{$c->id}", ['parent_device_id' => $a->id])->assertOk();
        $this->assertDatabaseHas('devices', ['id' => $c->id, 'parent_device_id' => $a->id]);
    }

    public function test_deleting_a_device_takes_its_links_placements_and_interfaces_with_it(): void
    {
        // GitHub #45 - "Delete device" on the map. The DB cascade does the work; this pins the
        // blast radius the confirmation dialog promises.
        $device = Device::factory()->create();
        $peer = Device::factory()->create();
        $iface = NetworkInterface::factory()->for($device)->create();
        $peerIface = NetworkInterface::factory()->for($peer)->create();
        $link = Link::create([
            'a_device_id' => $device->id, 'a_interface_id' => $iface->id,
            'b_device_id' => $peer->id, 'b_interface_id' => $peerIface->id,
        ]);
        $map = Map::factory()->create();
        DeviceMapPosition::create(['device_id' => $device->id, 'map_id' => $map->id, 'x' => 1, 'y' => 2]);
        DeviceMapPosition::create(['device_id' => $peer->id, 'map_id' => $map->id, 'x' => 3, 'y' => 4]);

        $this->deleteJson("/api/devices/{$device->id}")->assertNoContent();

        $this->assertDatabaseMissing('devices', ['id' => $device->id]);
        $this->assertDatabaseMissing('links', ['id' => $link->id]);
        $this->assertDatabaseMissing('interfaces', ['id' => $iface->id]);
        $this->assertDatabaseMissing('device_map_positions', ['device_id' => $device->id]);

        // The device at the far end of the link is untouched - only its link is gone.
        $this->assertDatabaseHas('devices', ['id' => $peer->id]);
        $this->assertDatabaseHas('interfaces', ['id' => $peerIface->id]);
        $this->assertDatabaseHas('device_map_positions', ['device_id' => $peer->id]);
    }

    public function test_deleting_a_device_leaves_its_children_parentless_but_alive(): void
    {
        // What the delete confirmation warns about: children survive and fall back to no
        // parent (`parent_device_id` is nullOnDelete), they are not deleted with it.
        $parent = Device::factory()->create();
        $child = Device::factory()->create(['parent_device_id' => $parent->id]);
        $grandchild = Device::factory()->create(['parent_device_id' => $child->id]);

        $this->deleteJson("/api/devices/{$parent->id}")->assertNoContent();

        $this->assertDatabaseHas('devices', ['id' => $child->id, 'parent_device_id' => null]);
        $this->assertDatabaseHas('devices', ['id' => $grandchild->id, 'parent_device_id' => $child->id]);
    }

    public function test_it_creates_a_ping_only_device(): void
    {
        //  (FR-36): `none` is a valid poll method - pinged for up/down, no throughput.
        $this->postJson('/api/devices', ['name' => 'Shed Camera', 'mgmt_ip' => '192.168.1.50', 'poll_method' => 'none'])
            ->assertCreated()
            ->assertJsonPath('data.poll_method', 'none');

        $this->assertDatabaseHas('devices', ['name' => 'Shed Camera', 'poll_method' => 'none']);
    }

    public function test_it_can_switch_an_existing_device_to_ping_only(): void
    {
        $device = Device::factory()->create(['poll_method' => 'snmp']);

        $this->putJson("/api/devices/{$device->id}", ['poll_method' => 'none'])
            ->assertOk()
            ->assertJsonPath('data.poll_method', 'none');

        $this->assertDatabaseHas('devices', ['id' => $device->id, 'poll_method' => 'none']);
    }

    public function test_it_rejects_a_loopback_management_ip(): void
    {
        // : reject obviously-wrong mgmt IPs (loopback = the monitor box itself)
        // at the door via the ManageableIp rule; a normal IP still passes.
        $this->postJson('/api/devices', ['name' => 'Bad', 'mgmt_ip' => '127.0.0.1', 'poll_method' => 'snmp'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mgmt_ip']);

        $this->postJson('/api/devices', ['name' => 'Multicast', 'mgmt_ip' => '224.0.0.1', 'poll_method' => 'snmp'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mgmt_ip']);

        $this->postJson('/api/devices', ['name' => 'Good', 'mgmt_ip' => '10.0.0.5', 'poll_method' => 'snmp'])
            ->assertCreated();
    }

    public function test_it_rejects_updating_to_a_loopback_management_ip(): void
    {
        $device = Device::factory()->create(['mgmt_ip' => '10.0.0.7']);

        $this->putJson("/api/devices/{$device->id}", ['mgmt_ip' => '127.0.0.1'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mgmt_ip']);
    }
}
