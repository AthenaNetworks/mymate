<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Site;
use App\Support\DeviceGeo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Devices inherit their site's coordinates on the geo map, unless they carry their own pin.
 * This is the whole point of sites - place a tower once, every device at it follows. The site
 * slots into DeviceGeo's resolution between the device's own pin and the uplink-ancestor walk.
 */
class DeviceSiteGeoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsUser();
    }

    public function test_a_device_inherits_its_sites_coordinates(): void
    {
        $site = Site::factory()->at(40.12345, -100.54321)->create();
        Device::factory()->create(['site_id' => $site->id, 'latitude' => null, 'longitude' => null]);

        $this->getJson('/api/devices')
            ->assertOk()
            ->assertJsonPath('data.0.geo_latitude', 40.12345)
            ->assertJsonPath('data.0.geo_longitude', -100.54321)
            ->assertJsonPath('data.0.geo_inherited', true)
            ->assertJsonPath('data.0.site_name', $site->name);
    }

    public function test_a_devices_own_pin_wins_over_its_site(): void
    {
        $site = Site::factory()->at(40.12345, -100.54321)->create();
        $device = Device::factory()->create(['site_id' => $site->id, 'latitude' => 10.0, 'longitude' => 20.0]);

        DeviceGeo::apply([$device->load('site')]);

        $this->assertSame(10.0, $device->geo_latitude);
        $this->assertSame(20.0, $device->geo_longitude);
        $this->assertFalse($device->geo_inherited);
    }

    public function test_a_devices_site_wins_over_its_uplink_ancestor(): void
    {
        $site = Site::factory()->at(40.0, -100.0)->create();
        $tower = Device::factory()->create(['latitude' => -27.4, 'longitude' => 153.1]);
        $cpe = Device::factory()->create([
            'site_id' => $site->id, 'latitude' => null, 'longitude' => null, 'parent_device_id' => $tower->id,
        ]);

        DeviceGeo::apply([$tower, $cpe]);

        $this->assertSame(40.0, $cpe->geo_latitude);
        $this->assertSame(-100.0, $cpe->geo_longitude);
        $this->assertTrue($cpe->geo_inherited);
    }

    public function test_an_ancestor_placed_only_by_its_site_still_anchors_its_children(): void
    {
        $site = Site::factory()->at(40.0, -100.0)->create();
        $ap = Device::factory()->create(['site_id' => $site->id, 'latitude' => null, 'longitude' => null]);
        $cpe = Device::factory()->create([
            'latitude' => null, 'longitude' => null, 'parent_device_id' => $ap->id,
        ]);

        DeviceGeo::apply([$ap, $cpe]);

        $this->assertSame(40.0, $cpe->geo_latitude);
        $this->assertSame(-100.0, $cpe->geo_longitude);
        $this->assertTrue($cpe->geo_inherited);
    }

    public function test_a_device_with_no_pin_and_an_unplaced_site_has_no_coordinates(): void
    {
        $site = Site::factory()->unplaced()->create();
        Device::factory()->create(['site_id' => $site->id, 'latitude' => null, 'longitude' => null]);

        $this->getJson('/api/devices')->assertJsonPath('data.0.geo_latitude', null);
    }

    public function test_the_single_device_endpoint_resolves_site_coordinates_too(): void
    {
        $site = Site::factory()->at(40.12345, -100.54321)->create();
        $device = Device::factory()->create(['site_id' => $site->id, 'latitude' => null, 'longitude' => null]);

        $this->getJson("/api/devices/{$device->id}")
            ->assertOk()
            ->assertJsonPath('data.geo_latitude', 40.12345)
            ->assertJsonPath('data.geo_longitude', -100.54321);
    }

    public function test_assigning_a_site_through_the_editor_marks_it_manual(): void
    {
        $site = Site::factory()->at(40.5, -100.25)->create();
        $device = Device::factory()->create(['latitude' => null, 'longitude' => null]);

        $this->patchJson("/api/devices/{$device->id}", ['site_id' => $site->id])
            ->assertOk()
            ->assertJsonPath('data.site_id', $site->id)
            ->assertJsonPath('data.site_source', 'manual')
            ->assertJsonPath('data.geo_latitude', 40.5);
    }
}
