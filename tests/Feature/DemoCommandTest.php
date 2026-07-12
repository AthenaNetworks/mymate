<?php

namespace Tests\Feature;

use App\Events\InterfaceUtilUpdated;
use App\Models\Device;
use App\Models\NetworkInterface;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * The customer-facing demo driver (synthetic data). Seed builds a read-only viewer +
 * mock topology; the simulator moves util and broadcasts over the real event pipeline.
 */
class DemoCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_creates_a_readonly_viewer_and_mock_topology(): void
    {
        $this->artisan('mymate:demo --seed')->assertOk();

        $viewer = User::where('email', config('mymate.demo.email'))->first();
        $this->assertNotNull($viewer);
        $this->assertFalse($viewer->is_admin);                 // read-only tier
        $this->assertTrue($viewer->isAdmin() === false);

        $this->assertTrue(Device::where('monitored', false)->exists()); // Mock Lab seeded

        // The default map must be the POPULATED one (so the demo doesn't open empty).
        $default = \App\Models\Map::where('is_default', true)->withCount('positions')->first();
        $this->assertNotNull($default);
        $this->assertGreaterThan(0, $default->positions_count);
    }

    public function test_simulator_tick_moves_util_and_broadcasts(): void
    {
        $this->artisan('mymate:demo --seed')->assertOk();
        Event::fake([InterfaceUtilUpdated::class]);

        $this->artisan('mymate:demo --run --once')->assertOk();

        // Interfaces on UP mock devices got live util + bps; the live event fired.
        // (Down devices are intentionally left null so their edges grey out.)
        $mockIfaces = NetworkInterface::whereHas('device', fn ($q) => $q->where('monitored', false));
        $this->assertTrue((clone $mockIfaces)->whereNotNull('util_in')->exists());
        $this->assertTrue((clone $mockIfaces)->whereNotNull('bps_in')->exists());
        Event::assertDispatched(InterfaceUtilUpdated::class);
    }

    public function test_demo_flag_and_viewer_creds_are_injected_only_when_enabled(): void
    {
        config(['mymate.demo.enabled' => true, 'mymate.demo.email' => 'demo@x.test', 'mymate.demo.password' => 'sekret-demo']);
        $this->get('/')
            ->assertOk()
            ->assertSee('name="mymate:demo" content="1"', false) // meta tag, not an inline script (CSP)
            ->assertSee('demo@x.test')
            ->assertSee('sekret-demo');

        config(['mymate.demo.enabled' => false]);
        $this->get('/')
            ->assertOk()
            ->assertDontSee('mymate:demo', false) // no demo meta on a real instance
            ->assertDontSee('sekret-demo');       // no creds leak
    }

    public function test_down_devices_raise_synthetic_alerts_and_outages(): void
    {
        $this->artisan('mymate:demo --seed')->assertOk();
        // Both alert policies + outages are seeded.
        $this->assertDatabaseHas('alert_policies', ['name' => 'Device down', 'enabled' => true]);
        $this->assertDatabaseHas('alert_policies', ['name' => 'Link capacity exceeded', 'enabled' => true]);
        $this->assertTrue(\App\Models\Outage::exists());                        // historical + open outages
        $this->assertTrue(\App\Models\Outage::whereNull('ended_at')->exists()); // an open outage for a down device

        $this->artisan('mymate:demo --run --once')->assertOk();

        // The Mock Lab includes a down node -> a firing device-down alert.
        $this->assertTrue(\App\Models\AlertEvent::where('status', 'firing')->exists());
    }

    public function test_clear_removes_the_viewer_and_topology(): void
    {
        $this->artisan('mymate:demo --seed')->assertOk();
        $this->artisan('mymate:demo --clear')->assertOk();

        $this->assertNull(User::where('email', config('mymate.demo.email'))->first());
        $this->assertFalse(Device::where('monitored', false)->exists());
    }
}
