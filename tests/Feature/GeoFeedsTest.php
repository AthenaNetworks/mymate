<?php

namespace Tests\Feature;

use App\Enums\DeviceStatus;
use App\Models\Device;
use App\Models\Outage;
use App\Models\Site;
use App\Models\SiteLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The compact geo feeds: /geo/devices (what the map draws, coordinates via DeviceGeo)
 * and /geo/backhauls (site-to-site links as coordinate pairs).
 */
class GeoFeedsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsUser();
    }

    public function test_devices_feed_resolves_site_coordinates_and_skips_the_unplaced(): void
    {
        $site = Site::factory()->at(40.1, -100.2)->create();
        $atSite = Device::factory()->create(['site_id' => $site->id, 'latitude' => null, 'longitude' => null]);
        Device::factory()->create(['name' => 'nowhere', 'latitude' => null, 'longitude' => null]);

        $rows = $this->getJson('/api/geo/devices')->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame($atSite->id, $rows[0]['id']);
        $this->assertSame(40.1, $rows[0]['lat']);
        $this->assertSame(-100.2, $rows[0]['lng']);
        $this->assertSame($site->id, $rows[0]['site_id']);
    }

    public function test_devices_feed_inherits_through_an_unmonitored_uplink_parent(): void
    {
        // The parent is off the map (unmonitored) but still anchors its child's position.
        $parent = Device::factory()->create(['monitored' => false, 'latitude' => -27.4, 'longitude' => 153.1]);
        $cpe = Device::factory()->create([
            'latitude' => null, 'longitude' => null, 'parent_device_id' => $parent->id,
        ]);

        $rows = $this->getJson('/api/geo/devices')->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame($cpe->id, $rows[0]['id']);
        $this->assertSame(-27.4, $rows[0]['lat']);
    }

    public function test_devices_feed_reports_down_since_from_the_open_outage_without_duplicating(): void
    {
        $device = Device::factory()->create(['status' => DeviceStatus::Down, 'latitude' => 1.0, 'longitude' => 2.0]);
        // Two open outages (a racing poller's brief overlap) must not emit the device twice,
        // and the earlier start is the honest "how long has this been dark".
        $early = Outage::factory()->create(['device_id' => $device->id, 'started_at' => now()->subHours(5)]);
        Outage::factory()->create(['device_id' => $device->id, 'started_at' => now()->subHours(2)]);

        $rows = $this->getJson('/api/geo/devices')->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame($early->started_at->toIso8601String(), $rows[0]['down_since']);
    }

    public function test_backhauls_feed_returns_coordinate_pairs_and_skips_unplaced_ends(): void
    {
        $a = Site::factory()->at(40.5, -100.25)->create();
        $b = Site::factory()->at(41.5, -101.75)->create();
        $unplaced = Site::factory()->unplaced()->create();
        $link = SiteLink::create(['site_a_id' => $a->id, 'site_b_id' => $b->id, 'media_type' => 'wireless']);
        SiteLink::create(['site_a_id' => $a->id, 'site_b_id' => $unplaced->id, 'media_type' => 'fiber']);

        $rows = $this->getJson('/api/geo/backhauls')->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame($link->id, $rows[0]['id']);
        $this->assertSame('wireless', $rows[0]['media_type']);
        $this->assertSame([-100.25, 40.5], $rows[0]['a']);
        $this->assertSame([-101.75, 41.5], $rows[0]['b']);
    }
}
