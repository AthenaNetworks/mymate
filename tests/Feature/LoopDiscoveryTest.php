<?php

namespace Tests\Feature;

use App\Enums\PollMethod;
use App\Jobs\DiscoverInterfacesJob;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LoopDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_discover_dispatches_for_both_poll_methods(): void
    {
        // : periodic re-discovery now covers SNMP *and* RouterOS
        // (it was SNMP-only, leaving RouterOS devices un-refreshed).
        Queue::fake();
        $snmp = Device::factory()->create(['poll_method' => PollMethod::Snmp]);
        $routerOs = Device::factory()->create(['poll_method' => PollMethod::RouterOs]);

        $this->artisan('mymate:loop --discover')->assertSuccessful();

        Queue::assertPushed(DiscoverInterfacesJob::class, fn ($j): bool => $j->deviceId === $snmp->id);
        Queue::assertPushed(DiscoverInterfacesJob::class, fn ($j): bool => $j->deviceId === $routerOs->id);
        Queue::assertPushed(DiscoverInterfacesJob::class, 2);
    }

    public function test_ping_only_devices_are_not_discovered(): void
    {
        //  (FR-36): a ping-only device has no throughput/discovery driver, so
        // it must be excluded from the discovery sweep too - not just throughput.
        Queue::fake();
        $snmp = Device::factory()->create(['poll_method' => PollMethod::Snmp]);
        $pingOnly = Device::factory()->create(['poll_method' => PollMethod::None]);

        $this->artisan('mymate:loop --discover')->assertSuccessful();

        Queue::assertPushed(DiscoverInterfacesJob::class, fn ($j): bool => $j->deviceId === $snmp->id);
        Queue::assertNotPushed(DiscoverInterfacesJob::class, fn ($j): bool => $j->deviceId === $pingOnly->id);
        Queue::assertPushed(DiscoverInterfacesJob::class, 1);
    }
}
