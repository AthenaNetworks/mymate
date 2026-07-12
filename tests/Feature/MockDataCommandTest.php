<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceMapPosition;
use App\Models\Link;
use App\Models\Map;
use App\Models\NetworkInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MockDataCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_a_mock_map_with_unmonitored_devices_and_links(): void
    {
        $this->artisan('mymate:mock')->assertSuccessful();

        $this->assertDatabaseHas('maps', ['name' => 'Mock Lab']);
        $map = Map::where('name', 'Mock Lab')->firstOrFail();

        // 8 devices, all unmonitored, all in the TEST-NET-2 range, all placed on the map.
        $devices = Device::where('mgmt_ip', 'like', '198.51.100.%')->get();
        $this->assertCount(8, $devices);
        $this->assertTrue($devices->every(fn (Device $d) => $d->monitored === false));
        $this->assertSame(8, DeviceMapPosition::where('map_id', $map->id)->count());

        $this->assertGreaterThan(0, NetworkInterface::whereIn('device_id', $devices->modelKeys())->count());
        $this->assertSame(7, Link::count());

        // A down node and the asymmetric uplink are present (colour-ramp coverage). The
        // asymmetry now lives on the LINK, not the interface.
        $this->assertDatabaseHas('devices', ['name' => 'AP-NORTH', 'status' => 'down', 'monitored' => false]);
        $this->assertDatabaseHas('links', ['bw_ab_mbps' => 500, 'bw_ba_mbps' => 50]);
    }

    public function test_clear_removes_only_the_mock_data(): void
    {
        $real = Device::factory()->create(['mgmt_ip' => '10.20.30.40']); // a real, monitored device

        $this->artisan('mymate:mock')->assertSuccessful();
        $this->artisan('mymate:mock', ['--clear' => true])->assertSuccessful();

        $this->assertDatabaseMissing('maps', ['name' => 'Mock Lab']);
        $this->assertSame(0, Device::where('mgmt_ip', 'like', '198.51.100.%')->count());
        $this->assertSame(0, Link::count()); // cascade dropped the mock links
        $this->assertDatabaseHas('devices', ['id' => $real->id]); // real device untouched
    }

    public function test_re_seeding_is_idempotent(): void
    {
        $this->artisan('mymate:mock')->assertSuccessful();
        $this->artisan('mymate:mock')->assertSuccessful(); // wipes the prior run first

        $this->assertSame(1, Map::where('name', 'Mock Lab')->count());
        $this->assertCount(8, Device::where('mgmt_ip', 'like', '198.51.100.%')->get());
    }
}
