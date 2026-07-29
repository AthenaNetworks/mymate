<?php

namespace Tests\Feature;

use App\Actions\Polling\PingFleet;
use App\Enums\DeviceStatus;
use App\Events\DeviceLatencyUpdated;
use App\Events\DeviceStatusChanged;
use App\Models\Device;
use App\Models\Outage;
use App\Services\Ping\Pinger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class PingFleetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Most cases exercise the flip on a single sweep; the dampening threshold has its own
        // test below. Default it to 1 here so a single miss flips (the pre-dampening behaviour).
        config(['mymate.ping.fail_threshold' => 1]);
    }

    /** Bind a fake Pinger that reports only the given IPs as reachable. */
    private function fakePinger(array $reachable): void
    {
        $this->app->bind(Pinger::class, fn () => new class($reachable) implements Pinger
        {
            public function __construct(private array $reachable) {}

            public function reachable(array $ips): array
            {
                return array_values(array_intersect($ips, $this->reachable));
            }

            public function measure(array $ips): array
            {
                $out = [];
                foreach ($ips as $ip) {
                    $up = in_array($ip, $this->reachable, true);
                    $out[$ip] = new \App\Services\Ping\PingSample(
                        reachable: $up,
                        rttMs: $up ? 12.5 : null,
                        lossPct: $up ? 0.0 : 100.0,
                        jitterMs: $up ? 1.5 : null,
                    );
                }

                return $out;
            }
        });
    }

    public function test_flips_status_and_broadcasts_only_for_changed_devices(): void
    {
        Event::fake([DeviceStatusChanged::class]);
        $up = Device::factory()->create(['mgmt_ip' => '10.0.0.1', 'status' => DeviceStatus::Unknown]);
        $down = Device::factory()->create(['mgmt_ip' => '10.0.0.2', 'status' => DeviceStatus::Unknown]);
        $this->fakePinger(['10.0.0.1']); // only the first device answers

        $changed = app(PingFleet::class)();

        $this->assertSame(2, $changed);
        $this->assertSame(DeviceStatus::Up, $up->refresh()->status);
        $this->assertSame(DeviceStatus::Down, $down->refresh()->status);
        $this->assertNotNull($up->last_change);
        Event::assertDispatchedTimes(DeviceStatusChanged::class, 2);
    }

    public function test_records_latency_history_and_live_columns(): void
    {
        $device = Device::factory()->create(['mgmt_ip' => '10.0.0.1', 'status' => DeviceStatus::Up]);
        $this->fakePinger(['10.0.0.1']); // reachable -> rtt 12.5, loss 0 (from the fake)

        app(PingFleet::class)();

        $device->refresh();
        $this->assertSame(12.5, $device->rtt_ms);
        $this->assertSame(0.0, $device->loss_pct);
        $this->assertNotNull($device->ping_at);
        $this->assertDatabaseHas('ping_samples', ['device_id' => $device->id, 'rtt_ms' => 12.5, 'loss_pct' => 0]);
    }

    public function test_broadcasts_live_latency_for_the_internet_card(): void
    {
        Event::fake([DeviceLatencyUpdated::class]);
        $device = Device::factory()->create(['mgmt_ip' => '10.0.0.1', 'status' => DeviceStatus::Up]);
        $this->fakePinger(['10.0.0.1']);

        app(PingFleet::class)();

        Event::assertDispatched(DeviceLatencyUpdated::class, function (DeviceLatencyUpdated $e) use ($device) {
            $frame = $e->devices[0];

            return $frame['device_id'] === $device->id && $frame['rtt_ms'] === 12.5 && $frame['loss_pct'] === 0.0;
        });
    }

    public function test_unchanged_status_emits_no_event(): void
    {
        Event::fake([DeviceStatusChanged::class]);
        Device::factory()->create(['mgmt_ip' => '10.0.0.1', 'status' => DeviceStatus::Up]);
        $this->fakePinger(['10.0.0.1']); // still reachable -> no change

        $this->assertSame(0, app(PingFleet::class)());
        Event::assertNotDispatched(DeviceStatusChanged::class);
    }

    public function test_records_an_outage_on_down_then_closes_it_on_recovery(): void
    {
        $device = Device::factory()->create(['mgmt_ip' => '10.0.0.9', 'status' => DeviceStatus::Up]);

        $this->fakePinger([]); // unreachable -> down -> outage opens
        app(PingFleet::class)();
        $this->assertDatabaseHas('outages', ['device_id' => $device->id, 'ended_at' => null]);

        $this->fakePinger(['10.0.0.9']); // back up -> outage closes
        app(PingFleet::class)();

        $outage = Outage::where('device_id', $device->id)->first();
        $this->assertNotNull($outage->ended_at);
        $this->assertNotNull($outage->duration_s);
        $this->assertSame(1, Outage::where('device_id', $device->id)->count()); // no duplicate
    }

    public function test_skips_devices_with_monitoring_disabled(): void
    {
        Event::fake([DeviceStatusChanged::class]);
        $monitored = Device::factory()->create(['mgmt_ip' => '10.0.0.1', 'status' => DeviceStatus::Up]);
        $paused = Device::factory()->create(['mgmt_ip' => '10.0.0.2', 'status' => DeviceStatus::Up, 'monitored' => false]);
        $this->fakePinger([]); // nothing answers - a monitored device would flip to down

        $changed = app(PingFleet::class)();

        $this->assertSame(1, $changed); // only the monitored device flipped
        $this->assertSame(DeviceStatus::Down, $monitored->refresh()->status);
        $this->assertSame(DeviceStatus::Up, $paused->refresh()->status); // untouched - never pinged
        Event::assertDispatchedTimes(DeviceStatusChanged::class, 1);
    }

    public function test_event_broadcasts_on_map_channel_with_payload(): void
    {
        $device = Device::factory()->create(['mgmt_ip' => '10.0.0.1', 'status' => DeviceStatus::Up]);

        $event = new DeviceStatusChanged($device);

        $this->assertSame('private-map', $event->broadcastOn()->name);
        $this->assertSame('DeviceStatusChanged', $event->broadcastAs());
        $this->assertSame($device->id, $event->broadcastWith()['id']);
        $this->assertSame('up', $event->broadcastWith()['status']);
    }

    public function test_a_device_needs_repeated_misses_before_going_down(): void
    {
        config(['mymate.ping.fail_threshold' => 3]);
        $device = Device::factory()->create(['mgmt_ip' => '10.0.0.1', 'status' => DeviceStatus::Up]);
        $this->fakePinger([]); // unreachable every sweep

        // First two misses: still up (streak building), no change reported.
        $this->assertSame(0, app(PingFleet::class)());
        $this->assertSame(DeviceStatus::Up, $device->refresh()->status);
        $this->assertSame(1, $device->fail_streak);

        $this->assertSame(0, app(PingFleet::class)());
        $this->assertSame(DeviceStatus::Up, $device->refresh()->status);
        $this->assertSame(2, $device->fail_streak);

        // Third consecutive miss crosses the threshold -> down.
        $this->assertSame(1, app(PingFleet::class)());
        $this->assertSame(DeviceStatus::Down, $device->refresh()->status);
    }

    public function test_one_reply_recovers_immediately_and_resets_the_streak(): void
    {
        config(['mymate.ping.fail_threshold' => 3]);
        $device = Device::factory()->create(['mgmt_ip' => '10.0.0.1', 'status' => DeviceStatus::Down, 'fail_streak' => 3]);
        $this->fakePinger(['10.0.0.1']); // reachable again

        $this->assertSame(1, app(PingFleet::class)());
        $this->assertSame(DeviceStatus::Up, $device->refresh()->status);
        $this->assertSame(0, $device->fail_streak);
    }
}
