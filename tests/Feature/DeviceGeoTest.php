<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Support\DeviceGeo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Effective geo coordinate inheritance up the uplink chain (GitHub #21).
 */
class DeviceGeoTest extends TestCase
{
    use RefreshDatabase;

    public function test_own_coordinates_win(): void
    {
        $d = Device::factory()->create(['latitude' => -27.5, 'longitude' => 153.0]);
        DeviceGeo::apply([$d]);

        $this->assertSame(-27.5, $d->geo_latitude);
        $this->assertSame(153.0, $d->geo_longitude);
        $this->assertFalse($d->geo_inherited);
    }

    public function test_a_coordinateless_device_inherits_its_parent(): void
    {
        $tower = Device::factory()->create(['latitude' => -27.4, 'longitude' => 153.1]);
        $cpe = Device::factory()->create(['latitude' => null, 'longitude' => null, 'parent_device_id' => $tower->id]);

        DeviceGeo::apply([$tower, $cpe]);

        $this->assertSame(-27.4, $cpe->geo_latitude);
        $this->assertSame(153.1, $cpe->geo_longitude);
        $this->assertTrue($cpe->geo_inherited);
    }

    public function test_it_walks_multiple_hops_to_the_nearest_placed_ancestor(): void
    {
        $pop = Device::factory()->create(['latitude' => -27.0, 'longitude' => 152.9]);
        $ap = Device::factory()->create(['latitude' => null, 'longitude' => null, 'parent_device_id' => $pop->id]);
        $cpe = Device::factory()->create(['latitude' => null, 'longitude' => null, 'parent_device_id' => $ap->id]);

        DeviceGeo::apply([$pop, $ap, $cpe]);

        $this->assertSame(-27.0, $cpe->geo_latitude);
        $this->assertTrue($cpe->geo_inherited);
    }

    public function test_no_placed_ancestor_leaves_it_unplaced(): void
    {
        $parent = Device::factory()->create(['latitude' => null, 'longitude' => null]);
        $cpe = Device::factory()->create(['latitude' => null, 'longitude' => null, 'parent_device_id' => $parent->id]);

        DeviceGeo::apply([$parent, $cpe]);

        $this->assertNull($cpe->geo_latitude);
        $this->assertFalse($cpe->geo_inherited);
    }

    public function test_a_parent_cycle_does_not_loop_forever(): void
    {
        $a = Device::factory()->create(['latitude' => null, 'longitude' => null]);
        $b = Device::factory()->create(['latitude' => null, 'longitude' => null, 'parent_device_id' => $a->id]);
        $a->update(['parent_device_id' => $b->id]); // A <-> B cycle, neither placed

        DeviceGeo::apply([$a, $b]);

        $this->assertNull($a->geo_latitude);
        $this->assertNull($b->geo_latitude);
    }

    public function test_index_endpoint_exposes_inherited_coordinates(): void
    {
        $this->actingAsUser();
        $tower = Device::factory()->create(['latitude' => -27.4, 'longitude' => 153.1]);
        $cpe = Device::factory()->create(['name' => 'cpe1', 'latitude' => null, 'longitude' => null, 'parent_device_id' => $tower->id]);

        $row = collect($this->getJson('/api/devices')->assertOk()->json('data'))->firstWhere('id', $cpe->id);

        $this->assertSame(-27.4, $row['geo_latitude']);
        $this->assertTrue($row['geo_inherited']);
        $this->assertNull($row['latitude']); // its own coords are still null
    }
}
