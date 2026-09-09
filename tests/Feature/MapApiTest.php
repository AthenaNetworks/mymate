<?php

namespace Tests\Feature;

use App\Actions\Devices\CreateDevice;
use App\Enums\PollMethod;
use App\Models\Device;
use App\Models\DeviceMapPosition;
use App\Models\Link;
use App\Models\Map;
use App\Models\MapNote;
use App\Models\NetworkInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MapApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_includes_the_seeded_default_map(): void
    {
        $this->actingAsUser();

        $res = $this->getJson('/api/maps')->assertOk();
        $default = collect($res->json('data'))->firstWhere('is_default', true);
        $this->assertNotNull($default);
        $this->assertSame('Main', $default['name']);
    }

    public function test_create_nest_and_delete_maps(): void
    {
        $this->actingAsUser();

        $region = $this->postJson('/api/maps', ['name' => 'Region'])->assertCreated()->json('data.id');
        $town = $this->postJson('/api/maps', ['name' => 'Town', 'parent_map_id' => $region])
            ->assertCreated()->assertJsonPath('data.parent_map_id', $region)->json('data.id');

        // A map can't be its own parent.
        $this->putJson("/api/maps/{$town}", ['parent_map_id' => $town])->assertStatus(422);

        $this->deleteJson("/api/maps/{$town}")->assertNoContent();
        $this->assertDatabaseMissing('maps', ['id' => $town]);
    }

    public function test_toggles_and_reports_geographic_mode(): void
    {
        $this->actingAsUser();
        $id = $this->postJson('/api/maps', ['name' => 'Sites'])->assertCreated()->json('data.id');

        $this->putJson("/api/maps/{$id}", ['name' => 'Sites', 'leaflet_enabled' => true])
            ->assertOk()->assertJsonPath('data.leaflet_enabled', true);

        $this->getJson("/api/maps/{$id}")->assertOk()->assertJsonPath('data.leaflet_enabled', true);
        $this->assertDatabaseHas('maps', ['id' => $id, 'leaflet_enabled' => true]);
    }

    public function test_default_map_cannot_be_deleted(): void
    {
        $this->actingAsUser();
        $default = Map::default();

        $this->deleteJson("/api/maps/{$default->id}")->assertStatus(422);
        $this->assertDatabaseHas('maps', ['id' => $default->id]);
    }

    public function test_show_returns_positions_and_inter_map_links(): void
    {
        $this->actingAsUser();
        $townA = Map::factory()->create(['name' => 'Town A']);
        $townB = Map::factory()->create(['name' => 'Town B']);
        $devA = Device::factory()->create();
        $devB = Device::factory()->create();
        DeviceMapPosition::create(['device_id' => $devA->id, 'map_id' => $townA->id, 'x' => 5, 'y' => 6]);
        DeviceMapPosition::create(['device_id' => $devB->id, 'map_id' => $townB->id, 'x' => 0, 'y' => 0]);

        $ifA = NetworkInterface::factory()->create(['device_id' => $devA->id]);
        $ifB = NetworkInterface::factory()->create(['device_id' => $devB->id]);
        Link::create(['a_device_id' => $devA->id, 'a_interface_id' => $ifA->id, 'b_device_id' => $devB->id, 'b_interface_id' => $ifB->id]);

        $this->getJson("/api/maps/{$townA->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.positions')
            ->assertJsonPath('data.positions.0.device_id', $devA->id)
            ->assertJsonCount(1, 'data.inter_map_links')
            ->assertJsonPath('data.inter_map_links.0.local_device_id', $devA->id)
            ->assertJsonPath('data.inter_map_links.0.remote_map_id', $townB->id)
            ->assertJsonPath('data.inter_map_links.0.remote_map_name', 'Town B')
            ->assertJsonPath('data.inter_map_links.0.remote_device_name', $devB->name) // names the peer device
            ->assertJsonPath('data.inter_map_links.0.portal_x', null); // not yet positioned
    }

    public function test_link_to_a_device_on_no_map_produces_no_portal(): void
    {
        $this->actingAsUser();
        $townA = Map::factory()->create(['name' => 'Town A']);
        $core = Device::factory()->create();
        $client = Device::factory()->create(); // monitored but placed on NO map (hidden)
        DeviceMapPosition::create(['device_id' => $core->id, 'map_id' => $townA->id, 'x' => 5, 'y' => 6]);

        $ifA = NetworkInterface::factory()->create(['device_id' => $core->id]);
        $ifB = NetworkInterface::factory()->create(['device_id' => $client->id]);
        Link::create(['a_device_id' => $core->id, 'a_interface_id' => $ifA->id, 'b_device_id' => $client->id, 'b_interface_id' => $ifB->id]);

        // There is nowhere to navigate to, so no dead "other map" portal stub is emitted.
        $this->getJson("/api/maps/{$townA->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.positions')
            ->assertJsonCount(0, 'data.inter_map_links');
    }

    public function test_inter_map_link_portal_position_persists_and_is_returned(): void
    {
        $this->actingAsUser();
        $townA = Map::factory()->create(['name' => 'Town A']);
        $townB = Map::factory()->create(['name' => 'Town B']);
        $devA = Device::factory()->create();
        $devB = Device::factory()->create();
        DeviceMapPosition::create(['device_id' => $devA->id, 'map_id' => $townA->id, 'x' => 5, 'y' => 6]);
        DeviceMapPosition::create(['device_id' => $devB->id, 'map_id' => $townB->id, 'x' => 0, 'y' => 0]);
        $ifA = NetworkInterface::factory()->create(['device_id' => $devA->id]);
        $ifB = NetworkInterface::factory()->create(['device_id' => $devB->id]);
        $link = Link::create(['a_device_id' => $devA->id, 'a_interface_id' => $ifA->id, 'b_device_id' => $devB->id, 'b_interface_id' => $ifB->id]);

        $this->patchJson("/api/maps/{$townA->id}/links/{$link->id}/position", ['x' => 321, 'y' => 654])->assertOk();
        $this->assertDatabaseHas('map_link_positions', ['map_id' => $townA->id, 'link_id' => $link->id, 'x' => 321, 'y' => 654]);

        $this->getJson("/api/maps/{$townA->id}")
            ->assertJsonPath('data.inter_map_links.0.portal_x', 321)
            ->assertJsonPath('data.inter_map_links.0.portal_y', 654);
    }

    public function test_save_position_and_add_remove_device(): void
    {
        $this->actingAsUser();
        $map = Map::factory()->create();
        $device = Device::factory()->create();

        // The add carries the drop point itself (no follow-up position save - GitHub #44).
        $this->postJson("/api/maps/{$map->id}/devices", ['device_id' => $device->id, 'x' => 1, 'y' => 2])->assertCreated();
        $this->assertDatabaseHas('device_map_positions', ['map_id' => $map->id, 'device_id' => $device->id, 'x' => 1, 'y' => 2]);

        $this->patchJson("/api/maps/{$map->id}/positions/{$device->id}", ['x' => 40, 'y' => 50])->assertOk();
        $this->assertDatabaseHas('device_map_positions', ['map_id' => $map->id, 'device_id' => $device->id, 'x' => 40, 'y' => 50]);

        $this->deleteJson("/api/maps/{$map->id}/devices/{$device->id}")->assertNoContent();
        $this->assertDatabaseMissing('device_map_positions', ['map_id' => $map->id, 'device_id' => $device->id]);
    }

    /** GitHub #44: a group drag / Tidy saves every moved node in one request, one transaction. */
    public function test_bulk_save_positions_persists_devices_portals_child_maps_and_notes(): void
    {
        $this->actingAsUser();
        $map = Map::factory()->create();
        $other = Map::factory()->create();
        [$a, $b, $c] = Device::factory()->count(3)->create();
        DeviceMapPosition::create(['device_id' => $a->id, 'map_id' => $map->id, 'x' => 1, 'y' => 1]);
        DeviceMapPosition::create(['device_id' => $b->id, 'map_id' => $map->id, 'x' => 2, 'y' => 2]);
        // $c is not on the map yet - a bulk save places it, same as the single-node save does.
        $remote = Device::factory()->create();
        DeviceMapPosition::create(['device_id' => $remote->id, 'map_id' => $other->id, 'x' => 0, 'y' => 0]);
        $ifA = NetworkInterface::factory()->create(['device_id' => $a->id]);
        $ifR = NetworkInterface::factory()->create(['device_id' => $remote->id]);
        $link = Link::create(['a_device_id' => $a->id, 'a_interface_id' => $ifA->id, 'b_device_id' => $remote->id, 'b_interface_id' => $ifR->id]);
        $child = Map::create(['name' => 'Child', 'parent_map_id' => $map->id, 'node_x' => 0, 'node_y' => 0]);
        $note = MapNote::create(['map_id' => $map->id, 'text' => 'rack', 'x' => 0, 'y' => 0]);

        $this->patchJson("/api/maps/{$map->id}/positions", [
            'devices' => [
                ['id' => $a->id, 'x' => 100, 'y' => 110],
                ['id' => $b->id, 'x' => 200, 'y' => 210],
                ['id' => $c->id, 'x' => 300, 'y' => 310],
            ],
            'portals' => [['link_id' => $link->id, 'x' => 400, 'y' => 410]],
            'child_maps' => [['id' => $child->id, 'x' => 500, 'y' => 510]],
            'notes' => [['id' => $note->id, 'x' => 600, 'y' => 610]],
        ])->assertOk()->assertJsonPath('saved', 6);

        $this->assertDatabaseHas('device_map_positions', ['map_id' => $map->id, 'device_id' => $a->id, 'x' => 100, 'y' => 110]);
        $this->assertDatabaseHas('device_map_positions', ['map_id' => $map->id, 'device_id' => $b->id, 'x' => 200, 'y' => 210]);
        $this->assertDatabaseHas('device_map_positions', ['map_id' => $map->id, 'device_id' => $c->id, 'x' => 300, 'y' => 310]);
        $this->assertDatabaseHas('map_link_positions', ['map_id' => $map->id, 'link_id' => $link->id, 'x' => 400, 'y' => 410]);
        $this->assertDatabaseHas('maps', ['id' => $child->id, 'parent_map_id' => $map->id, 'node_x' => 500, 'node_y' => 510]);
        $this->assertDatabaseHas('map_notes', ['id' => $note->id, 'x' => 600, 'y' => 610]);
        // The other map's placement of the remote device is untouched.
        $this->assertDatabaseHas('device_map_positions', ['map_id' => $other->id, 'device_id' => $remote->id, 'x' => 0, 'y' => 0]);

        // Round-trips through show, so a refetch mid-drag returns the new layout, not a partial one.
        $this->getJson("/api/maps/{$map->id}")->assertOk()->assertJsonCount(3, 'data.positions');
    }

    public function test_bulk_save_positions_rejects_bad_rows_and_saves_nothing(): void
    {
        $this->actingAsUser();
        $map = Map::factory()->create();
        $device = Device::factory()->create();
        DeviceMapPosition::create(['device_id' => $device->id, 'map_id' => $map->id, 'x' => 1, 'y' => 1]);
        // A child map that hangs off a DIFFERENT canvas, and a note on a different map, can't
        // be moved through this map's endpoint.
        $elsewhere = Map::factory()->create();
        $foreignChild = Map::create(['name' => 'Foreign', 'parent_map_id' => $elsewhere->id, 'node_x' => 0, 'node_y' => 0]);
        $foreignNote = MapNote::create(['map_id' => $elsewhere->id, 'text' => 'x', 'x' => 0, 'y' => 0]);

        $this->patchJson("/api/maps/{$map->id}/positions", [
            'devices' => [['id' => $device->id, 'x' => 50, 'y' => 60]],
            'child_maps' => [['id' => $foreignChild->id, 'x' => 9, 'y' => 9]],
            'notes' => [['id' => $foreignNote->id, 'x' => 9, 'y' => 9]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['child_maps.0.id', 'notes.0.id']);

        $this->patchJson("/api/maps/{$map->id}/positions", ['devices' => [['id' => 999999, 'x' => 1, 'y' => 2]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['devices.0.id']);
        $this->patchJson("/api/maps/{$map->id}/positions", ['devices' => [['id' => $device->id, 'x' => 'left', 'y' => 2]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['devices.0.x']);

        // Validation failed as a whole, so the good device row was not applied either.
        $this->assertDatabaseHas('device_map_positions', ['map_id' => $map->id, 'device_id' => $device->id, 'x' => 1, 'y' => 1]);
        $this->assertDatabaseHas('maps', ['id' => $foreignChild->id, 'node_x' => 0]);
        $this->assertDatabaseHas('map_notes', ['id' => $foreignNote->id, 'x' => 0]);

        // Empty body is a no-op, not an error.
        $this->patchJson("/api/maps/{$map->id}/positions", [])->assertOk()->assertJsonPath('saved', 0);
    }

    public function test_new_device_auto_joins_the_default_map(): void
    {
        $device = app(CreateDevice::class)(['name' => 'NewDev', 'mgmt_ip' => '10.0.0.77', 'poll_method' => PollMethod::Snmp]);

        $this->assertDatabaseHas('device_map_positions', ['device_id' => $device->id, 'map_id' => Map::default()->id]);
    }

    public function test_create_device_can_skip_map_placement(): void
    {
        $device = app(CreateDevice::class)([
            'name' => 'Client', 'mgmt_ip' => '10.0.0.78', 'poll_method' => PollMethod::None, 'place_on_map' => false,
        ]);

        $this->assertDatabaseHas('devices', ['id' => $device->id, 'name' => 'Client']); // still in the fleet
        $this->assertDatabaseMissing('device_map_positions', ['device_id' => $device->id]); // on no map
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/maps')->assertUnauthorized();
    }
}
