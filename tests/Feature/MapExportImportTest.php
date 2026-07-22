<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceMapPosition;
use App\Models\Link;
use App\Models\Map;
use App\Models\MapNote;
use App\Models\NetworkInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GitHub #11: export a map's layout to portable JSON and rebuild it - for migrating a map (and
 * its devices/connections) between instances/versions.
 */
class MapExportImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsUser();
    }

    private function populatedMap(): Map
    {
        $map = Map::create(['name' => 'Site 7']);
        $a = Device::factory()->create(['name' => 'RTR-A', 'mgmt_ip' => '10.9.0.1']);
        $b = Device::factory()->create(['name' => 'SW-B', 'mgmt_ip' => '10.9.0.2']);
        $aIf = NetworkInterface::factory()->for($a)->create(['name' => 'ether1']);
        $bIf = NetworkInterface::factory()->for($b)->create(['name' => 'ether24']);
        DeviceMapPosition::create(['map_id' => $map->id, 'device_id' => $a->id, 'x' => 10, 'y' => 20]);
        DeviceMapPosition::create(['map_id' => $map->id, 'device_id' => $b->id, 'x' => 200, 'y' => 40]);
        Link::create([
            'a_device_id' => $a->id, 'a_interface_id' => $aIf->id,
            'b_device_id' => $b->id, 'b_interface_id' => $bIf->id, 'media_type' => 'fiber',
        ]);
        MapNote::create(['map_id' => $map->id, 'text' => 'core rack', 'x' => 5, 'y' => 5]);

        return $map;
    }

    public function test_export_contains_the_layout_without_secrets(): void
    {
        $map = $this->populatedMap();

        $res = $this->getJson("/api/maps/{$map->id}/export")->assertOk();

        $res->assertJsonPath('map.name', 'Site 7')
            ->assertJsonPath('devices.0.mgmt_ip', '10.9.0.1')
            ->assertJsonPath('links.0.media_type', 'fiber')
            ->assertJsonPath('links.0.a_if', 'ether1')
            ->assertJsonPath('notes.0.text', 'core rack');
        // Never leak credentials in an export.
        $this->assertStringNotContainsString('community', $res->getContent());
        $this->assertStringNotContainsString('credential', $res->getContent());
    }

    public function test_import_matches_existing_devices_by_ip_and_restores_the_layout(): void
    {
        $map = $this->populatedMap();
        $export = $this->getJson("/api/maps/{$map->id}/export")->json();

        $deviceCountBefore = Device::count();

        $newId = $this->postJson('/api/maps/import', $export)->assertCreated()->json('data.id');

        // No new devices - both were matched by mgmt_ip.
        $this->assertSame($deviceCountBefore, Device::count());
        $this->assertNotSame($map->id, $newId);

        // Layout restored on the new map.
        $this->assertDatabaseHas('device_map_positions', ['map_id' => $newId, 'x' => 10, 'y' => 20]);
        $this->assertDatabaseHas('map_notes', ['map_id' => $newId, 'text' => 'core rack']);
        // The A<->B link isn't duplicated (the devices already had it) - still exactly one.
        $a = Device::where('mgmt_ip', '10.9.0.1')->first();
        $this->assertSame(1, Link::where('a_device_id', $a->id)->orWhere('b_device_id', $a->id)->count());
        $this->assertDatabaseHas('links', ['media_type' => 'fiber']);
    }

    public function test_import_creates_missing_devices_by_ip(): void
    {
        $map = $this->populatedMap();
        $export = $this->getJson("/api/maps/{$map->id}/export")->json();
        // Wipe the devices so import has to recreate them from the payload.
        DeviceMapPosition::query()->delete();
        Link::query()->delete();
        NetworkInterface::query()->delete();
        Device::query()->delete();

        $newId = $this->postJson('/api/maps/import', $export)->assertCreated()->json('data.id');

        $this->assertDatabaseHas('devices', ['mgmt_ip' => '10.9.0.1', 'name' => 'RTR-A']);
        $this->assertDatabaseHas('devices', ['mgmt_ip' => '10.9.0.2']);
        $this->assertSame(2, DeviceMapPosition::where('map_id', $newId)->count());
    }

    public function test_import_avoids_a_name_collision(): void
    {
        $map = $this->populatedMap();
        $export = $this->getJson("/api/maps/{$map->id}/export")->json();

        $this->postJson('/api/maps/import', $export)->assertCreated()->assertJsonPath('data.name', 'Site 7 (imported)');
    }
}
