<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceMapPosition;
use App\Models\Map;
use App\Models\MapLayoutSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The map layout undo stack: snapshot the current positions before a tidy, then roll back.
 */
class LayoutSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private function mapWithDevices(): array
    {
        $map = Map::create(['name' => 'Core']);
        $a = Device::factory()->create();
        $b = Device::factory()->create();
        DeviceMapPosition::create(['map_id' => $map->id, 'device_id' => $a->id, 'x' => 10, 'y' => 20]);
        DeviceMapPosition::create(['map_id' => $map->id, 'device_id' => $b->id, 'x' => 30, 'y' => 40]);

        return [$map, $a, $b];
    }

    public function test_snapshot_then_undo_restores_the_previous_positions(): void
    {
        $this->actingAsUser();
        [$map, $a, $b] = $this->mapWithDevices();

        // Capture the layout, then "tidy" moves the devices somewhere else.
        $this->postJson("/api/maps/{$map->id}/layout-snapshots")->assertCreated()->assertJsonPath('count', 1);
        DeviceMapPosition::where('map_id', $map->id)->where('device_id', $a->id)->update(['x' => 999, 'y' => 999]);
        DeviceMapPosition::where('map_id', $map->id)->where('device_id', $b->id)->update(['x' => 888, 'y' => 888]);

        $this->postJson("/api/maps/{$map->id}/layout-snapshots/undo")
            ->assertOk()
            ->assertJsonPath('remaining', 0)
            ->assertJsonPath("positions.{$a->id}.x", 10);

        // Positions are back and the snapshot was consumed.
        $this->assertDatabaseHas('device_map_positions', ['map_id' => $map->id, 'device_id' => $a->id, 'x' => 10, 'y' => 20]);
        $this->assertDatabaseHas('device_map_positions', ['map_id' => $map->id, 'device_id' => $b->id, 'x' => 30, 'y' => 40]);
        $this->assertSame(0, MapLayoutSnapshot::where('map_id', $map->id)->count());
    }

    public function test_undo_with_nothing_to_roll_back_is_a_422(): void
    {
        $this->actingAsUser();
        $map = Map::create(['name' => 'Empty']);
        $this->postJson("/api/maps/{$map->id}/layout-snapshots/undo")->assertStatus(422);
    }

    public function test_the_stack_is_trimmed_to_the_cap(): void
    {
        $this->actingAsUser();
        [$map] = $this->mapWithDevices();

        for ($i = 0; $i < 23; $i++) {
            $this->postJson("/api/maps/{$map->id}/layout-snapshots")->assertCreated();
        }

        $this->assertSame(20, MapLayoutSnapshot::where('map_id', $map->id)->count());
        $this->getJson("/api/maps/{$map->id}/layout-snapshots")->assertOk()->assertJsonPath('count', 20);
    }

    public function test_a_read_only_operator_cannot_snapshot_or_undo(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        [$map] = $this->mapWithDevices();

        $this->postJson("/api/maps/{$map->id}/layout-snapshots")->assertForbidden();
        $this->postJson("/api/maps/{$map->id}/layout-snapshots/undo")->assertForbidden();
        // ...but they can still read the count (GET is open).
        $this->getJson("/api/maps/{$map->id}/layout-snapshots")->assertOk();
    }
}
