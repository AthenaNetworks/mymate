<?php

namespace Tests\Feature;

use App\Actions\Devices\CreateDevice;
use App\Enums\PollMethod;
use App\Models\Device;
use App\Models\DeviceMapPosition;
use App\Models\Link;
use App\Models\Map;
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

        $this->postJson("/api/maps/{$map->id}/devices", ['device_id' => $device->id, 'x' => 1, 'y' => 2])->assertCreated();
        $this->assertDatabaseHas('device_map_positions', ['map_id' => $map->id, 'device_id' => $device->id]);

        $this->patchJson("/api/maps/{$map->id}/positions/{$device->id}", ['x' => 40, 'y' => 50])->assertOk();
        $this->assertDatabaseHas('device_map_positions', ['map_id' => $map->id, 'device_id' => $device->id, 'x' => 40, 'y' => 50]);

        $this->deleteJson("/api/maps/{$map->id}/devices/{$device->id}")->assertNoContent();
        $this->assertDatabaseMissing('device_map_positions', ['map_id' => $map->id, 'device_id' => $device->id]);
    }

    public function test_new_device_auto_joins_the_default_map(): void
    {
        $device = app(CreateDevice::class)(['name' => 'NewDev', 'mgmt_ip' => '10.0.0.77', 'poll_method' => PollMethod::Snmp]);

        $this->assertDatabaseHas('device_map_positions', ['device_id' => $device->id, 'map_id' => Map::default()->id]);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/maps')->assertUnauthorized();
    }
}
