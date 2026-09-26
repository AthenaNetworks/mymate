<?php

namespace Tests\Feature;

use App\Actions\Polling\PollDeviceMetrics;
use App\Actions\Polling\PollInterfaces;
use App\Actions\Polling\RecordDeviceResources;
use App\Enums\PollMethod;
use App\Events\DeviceMetricsUpdated;
use App\Events\DeviceRebooted;
use App\Events\InterfaceUtilUpdated;
use App\Models\Device;
use App\Models\DeviceMapPosition;
use App\Models\Link;
use App\Models\Map;
use App\Models\NetworkInterface;
use App\Models\User;
use App\Services\Polling\DeviceMetrics;
use App\Services\Polling\DeviceMetricsDriver;
use App\Services\Polling\DeviceMetricsDriverFactory;
use App\Services\Polling\InterfaceSample;
use App\Services\Polling\PortStatsDriver;
use App\Services\Polling\StorageReading;
use App\Services\Polling\ThroughputDriver;
use App\Services\Polling\ThroughputDriverFactory;
use App\Support\LiveBroadcast;
use App\Support\LiveWatch;
use App\Support\RestrictedAudience;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Tests\TestCase;

/**
 * The device page's numbers tick live: port oper status / rates / optical ride the util frames
 * (only when they change), a device that's open gets all its ports not just link ends, uptime /
 * per-CPU / a storage flag ride the metrics frames, and a reboot is pushed on its own. All of it
 * scoped to what a restricted operator may see.
 */
class DevicePageLiveTest extends TestCase
{
    use RefreshDatabase;

    public const T0 = 1_700_000_000;

    /** Every port: +1.25MB octets over 10s, oper down, and port counters when asked. */
    private function bindPortDriver(): void
    {
        $driver = new class implements PortStatsDriver, ThroughputDriver
        {
            public function discover(Device $device): array
            {
                return [];
            }

            public function sample(Device $device): array
            {
                $out = [];
                for ($i = 1; $i <= 60; $i++) {
                    $out[$i] = InterfaceSample::counters(2_250_000, 2_250_000, (float) (DevicePageLiveTest::T0 + 10), false);
                }

                return $out;
            }

            public function portCounters(Device $device, array $ifIndexes): array
            {
                return array_fill_keys($ifIndexes, ['pkts_in' => 7000, 'errors_in' => 160]);
            }
        };
        $this->app->instance(ThroughputDriverFactory::class, new class($driver) extends ThroughputDriverFactory
        {
            public function __construct(private ThroughputDriver $driver) {}

            public function for(Device $device): ThroughputDriver
            {
                return $this->driver;
            }
        });
    }

    /** @param  array<string, mixed>  $attrs */
    private function port(Device $device, int $ifIndex, array $attrs = []): NetworkInterface
    {
        return NetworkInterface::factory()->for($device)->create([
            'if_index' => $ifIndex, 'name' => "ether{$ifIndex}", 'speed_mbps' => 1000,
            'last_in' => 1_000_000, 'last_out' => 1_000_000,
            'last_ts' => Carbon::createFromTimestamp(self::T0),
            ...$attrs,
        ]);
    }

    private function link(NetworkInterface $a, NetworkInterface $b): void
    {
        Link::create([
            'a_device_id' => $a->device_id, 'a_interface_id' => $a->id,
            'b_device_id' => $b->device_id, 'b_interface_id' => $b->id,
        ]);
    }

    private function restrictedTo(Map $map): User
    {
        $user = User::factory()->create(['is_admin' => false]);
        $user->forceFill(['restricted' => true])->save();
        $user->maps()->attach($map->id);
        RestrictedAudience::forget();

        return $user;
    }

    private function place(Device $device, Map $map): void
    {
        DeviceMapPosition::create(['map_id' => $map->id, 'device_id' => $device->id, 'x' => 0, 'y' => 0]);
    }

    /** @return list<array<string, mixed>> every device frame broadcast on the shared channel */
    private function sharedFrames(): array
    {
        return collect(Event::dispatched(InterfaceUtilUpdated::class))
            ->map(fn ($args) => $args[0])
            ->filter(fn (InterfaceUtilUpdated $e) => $e->broadcastOn()->name === 'private-map')
            ->flatMap(fn (InterfaceUtilUpdated $e) => $e->devices)
            ->values()->all();
    }

    public function test_port_extras_ride_the_util_frame_only_when_they_changed(): void
    {
        Event::fake([InterfaceUtilUpdated::class]);
        $this->bindPortDriver();
        $device = Device::factory()->create(['poll_method' => PollMethod::Snmp]);
        $partner = Device::factory()->create();
        $stamp = Carbon::now()->subMinute();

        // flips down, counters due, optics read since the last util tick
        $fresh = $this->port($device, 1, [
            'oper_status' => 'up',
            'port_counters' => ['ts' => microtime(true) - 60, 'c' => ['pkts_in' => 1000, 'errors_in' => 100]],
            'optical_rx_dbm' => -5.1234, 'optical_tx_dbm' => -2.5, 'optical_at' => now(),
        ]);
        // already down, optics older than the last tick: nothing new to say
        $stale = $this->port($device, 2, [
            'oper_status' => 'down',
            'optical_rx_dbm' => -7.0, 'optical_at' => $stamp->copy()->subMinute(),
        ]);
        NetworkInterface::whereIn('id', [$fresh->id, $stale->id])->update(['updated_at' => $stamp]);
        $this->link($fresh, $this->port($partner, 1));
        $this->link($stale, $this->port($partner, 2));

        app(PollInterfaces::class)([$device->id]);

        $frames = $this->sharedFrames();
        $this->assertCount(1, $frames);
        $byId = collect($frames[0]['interfaces'])->keyBy('interface_id');

        $f = $byId[$fresh->id];
        $this->assertSame('down', $f['oper_status']);
        $this->assertEqualsWithDelta(100.0, $f['pkts_in'], 2.0);  // 6000 packets over ~60s
        $this->assertEqualsWithDelta(1.0, $f['errors_in'], 0.05);
        $this->assertSame(-5.12, $f['optical_rx_dbm']);
        $this->assertSame(-2.5, $f['optical_tx_dbm']);
        $this->assertArrayNotHasKey('pkts_out', $f); // not read, so not sent
        $this->assertArrayNotHasKey('device_id', $f); // it's on the device frame already
        $this->assertArrayNotHasKey('status', $f);
        $this->assertIsInt($f['bps_in']);

        $s = $byId[$stale->id];
        $this->assertArrayNotHasKey('oper_status', $s);
        $this->assertArrayNotHasKey('optical_rx_dbm', $s);
        $this->assertArrayNotHasKey('ports', $frames[0]); // nobody has it open
    }

    public function test_an_open_device_gets_its_other_ports_as_well(): void
    {
        Event::fake([InterfaceUtilUpdated::class]);
        $this->bindPortDriver();
        $open = Device::factory()->create(['poll_method' => PollMethod::Snmp]);
        $closed = Device::factory()->create(['poll_method' => PollMethod::Snmp]);
        $this->port($open, 1);
        $this->port($open, 2);
        $this->port($closed, 1);
        LiveWatch::touch($open->id);

        app(PollInterfaces::class)([$open->id, $closed->id]); // no links anywhere

        $frames = $this->sharedFrames();
        $this->assertSame([$open->id], array_column($frames, 'device_id'));
        $this->assertSame([], $frames[0]['interfaces']);
        $this->assertCount(2, $frames[0]['ports']);
        $this->assertArrayNotHasKey('speed_mbps', $frames[0]['ports'][0]); // the port list has it
    }

    public function test_a_big_open_device_is_split_to_fit_rather_than_dropped(): void
    {
        config(['mymate.poll.broadcast.max_bytes_per_event' => 1000]);
        Event::fake([InterfaceUtilUpdated::class]);
        $handler = new TestHandler;
        Log::channel('mymate')->getLogger()->pushHandler($handler);
        $this->bindPortDriver();
        $device = Device::factory()->create(['poll_method' => PollMethod::Snmp]);
        $partner = Device::factory()->create();
        $ports = [];
        for ($i = 1; $i <= 48; $i++) {
            $ports[] = $this->port($device, $i);
        }
        $this->link($ports[0], $this->port($partner, 1));
        LiveWatch::touch($device->id);

        app(PollInterfaces::class)([$device->id]);

        $events = Event::dispatched(InterfaceUtilUpdated::class);
        $this->assertGreaterThan(1, count($events));
        $sent = [];
        foreach ($events as [$e]) {
            $this->assertLessThanOrEqual(1000, strlen(json_encode($e->broadcastWith())));
            foreach ($e->devices as $d) {
                array_push($sent, ...array_column($d['interfaces'], 'interface_id'), ...array_column($d['ports'] ?? [], 'interface_id'));
            }
        }
        sort($sent);
        $this->assertSame(array_map(fn ($p) => $p->id, $ports), $sent); // every port, once
        $this->assertFalse($handler->hasWarningThatContains('exceeds byte budget'));
    }

    public function test_watching_is_an_operator_action_scoped_to_visible_devices(): void
    {
        $siteA = Map::create(['name' => 'Site A']);
        $mine = Device::factory()->create();
        $hidden = Device::factory()->create();
        $this->place($mine, $siteA);

        $this->actingAs(User::factory()->create(['is_admin' => false])); // read-only operator
        $this->postJson("/api/devices/{$hidden->id}/live")->assertOk();

        $this->actingAs($this->restrictedTo($siteA));
        $this->postJson("/api/devices/{$mine->id}/live")->assertOk();
        $other = Device::factory()->create(); // on no map of theirs
        $this->postJson("/api/devices/{$other->id}/live")->assertNotFound();

        $this->assertSame([$mine->id => true, $hidden->id => true], LiveWatch::watched([$mine->id, $hidden->id, $other->id]));
    }

    public function test_a_restricted_operator_only_gets_ports_of_their_own_devices(): void
    {
        Event::fake([InterfaceUtilUpdated::class]);
        $site = Map::create(['name' => 'Site A']);
        $alice = $this->restrictedTo($site);
        $mine = Device::factory()->create();
        $this->place($mine, $site);
        $theirs = Device::factory()->create();

        LiveBroadcast::send(new InterfaceUtilUpdated([
            ['device_id' => $mine->id, 'status' => 'up', 'interfaces' => [], 'ports' => [['interface_id' => 1, 'oper_status' => 'down']]],
            ['device_id' => $theirs->id, 'status' => 'up', 'interfaces' => [], 'ports' => [['interface_id' => 2, 'oper_status' => 'down']]],
        ]));

        Event::assertDispatched(InterfaceUtilUpdated::class, fn (InterfaceUtilUpdated $e) => $e->broadcastOn()->name === "private-map.user.{$alice->id}"
            && array_column($e->devices, 'device_id') === [$mine->id]
            && $e->devices[0]['ports'][0]['interface_id'] === 1);
        Event::assertDispatchedTimes(InterfaceUtilUpdated::class, 2); // shared + Alice's copy
    }

    public function test_metrics_frames_carry_uptime_per_cpu_and_a_storage_flag_scoped(): void
    {
        Event::fake([DeviceMetricsUpdated::class, DeviceRebooted::class]);
        $driver = new class implements DeviceMetricsDriver
        {
            public function sample(Device $device): DeviceMetrics
            {
                return $device->name === 'plain'
                    ? new DeviceMetrics(cpuPct: 5.0)
                    : new DeviceMetrics(
                        cpuPct: 20.0, uptimeSeconds: 3600, cpuLoads: [1 => 10.0, 2 => 30.04],
                        storages: [new StorageReading('31', 'flash', 'flash', 1000, 250)],
                    );
            }
        };
        $this->app->instance(DeviceMetricsDriverFactory::class, new class($driver) extends DeviceMetricsDriverFactory
        {
            public function __construct(private DeviceMetricsDriver $driver) {}

            public function for(Device $device): DeviceMetricsDriver
            {
                return $this->driver;
            }
        });
        $site = Map::create(['name' => 'Site A']);
        $alice = $this->restrictedTo($site);
        $full = Device::factory()->create(['poll_method' => PollMethod::Snmp, 'name' => 'full']);
        $plain = Device::factory()->create(['poll_method' => PollMethod::Snmp, 'name' => 'plain']);
        $this->place($full, $site);

        app(PollDeviceMetrics::class)([$full->id, $plain->id]);

        Event::assertDispatched(DeviceMetricsUpdated::class, function (DeviceMetricsUpdated $e) use ($full, $plain): bool {
            if ($e->broadcastOn()->name !== 'private-map') {
                return false;
            }
            $by = collect($e->devices)->keyBy('device_id');
            $f = $by[$full->id];
            $this->assertSame(3600, $f['uptime_seconds']);
            $this->assertSame([['index' => 1, 'load_pct' => 10.0], ['index' => 2, 'load_pct' => 30.0]], $f['cpu_loads']);
            $this->assertTrue($f['storage']);
            // nothing read, nothing sent
            $this->assertArrayNotHasKey('uptime_seconds', $by[$plain->id]);
            $this->assertArrayNotHasKey('cpu_loads', $by[$plain->id]);
            $this->assertArrayNotHasKey('storage', $by[$plain->id]);

            return true;
        });
        Event::assertDispatched(DeviceMetricsUpdated::class, fn (DeviceMetricsUpdated $e) => $e->broadcastOn()->name === "private-map.user.{$alice->id}"
            && array_column($e->devices, 'device_id') === [$full->id]);
        Event::assertNotDispatched(DeviceRebooted::class); // first uptime reading, nothing to compare
    }

    public function test_a_big_metrics_tick_is_split_to_fit(): void
    {
        config(['mymate.poll.broadcast.max_bytes_per_event' => 1000]);
        Event::fake([DeviceMetricsUpdated::class]);
        $frames = [];
        for ($i = 1; $i <= 40; $i++) {
            $frames[] = ['device_id' => $i, 'cpu_pct' => 1.5, 'mem_used_pct' => 2.5, 'temp_c' => null, 'uptime_seconds' => 100];
        }

        LiveBroadcast::sendFrames(fn (array $chunk) => new DeviceMetricsUpdated($chunk), $frames);

        $events = Event::dispatched(DeviceMetricsUpdated::class);
        $this->assertGreaterThan(1, count($events));
        $ids = [];
        foreach ($events as [$e]) {
            $this->assertLessThanOrEqual(1000, strlen(json_encode($e->broadcastWith())));
            array_push($ids, ...array_column($e->devices, 'device_id'));
        }
        $this->assertSame(range(1, 40), $ids);
    }

    public function test_a_reboot_is_pushed_and_scoped(): void
    {
        Event::fake([DeviceRebooted::class]);
        $site = Map::create(['name' => 'Site A']);
        $alice = $this->restrictedTo($site);
        $bob = $this->restrictedTo(Map::create(['name' => 'Site B']));
        $device = Device::factory()->create([
            'name' => 'sw1', 'uptime_seconds' => 41 * 86400, 'uptime_at' => now()->subMinute(),
        ]);
        $this->place($device, $site);

        RecordDeviceResources::deviceAttributes($device, new DeviceMetrics(uptimeSeconds: 120), now());

        $channels = [];
        Event::assertDispatched(DeviceRebooted::class, function (DeviceRebooted $e) use (&$channels, $device): bool {
            $channels[] = $e->broadcastOn()->name;
            $p = $e->broadcastWith();
            $this->assertSame($device->id, $p['device_id']);
            $this->assertSame('sw1', $p['name']);
            $this->assertSame(41 * 86400, $p['previous_uptime_s']);
            $this->assertNotNull($p['booted_at']);

            return true;
        });
        sort($channels);
        $this->assertSame(['private-map', "private-map.user.{$alice->id}"], $channels);
        $this->assertNotContains("private-map.user.{$bob->id}", $channels);
        $this->assertDatabaseHas('device_reboots', ['device_id' => $device->id]);
    }

    public function test_uptime_that_keeps_climbing_is_not_a_reboot(): void
    {
        Event::fake([DeviceRebooted::class]);
        $device = Device::factory()->create(['uptime_seconds' => 1000, 'uptime_at' => now()->subMinute()]);

        RecordDeviceResources::deviceAttributes($device, new DeviceMetrics(uptimeSeconds: 1060), now());

        Event::assertNotDispatched(DeviceRebooted::class);
    }

    public function test_the_single_device_read_carries_per_cpu_loads(): void
    {
        $this->actingAsUser();
        $device = Device::factory()->create(['cpu_loads' => [['index' => 1, 'load_pct' => 12.5]]]);

        $this->getJson("/api/devices/{$device->id}")->assertOk()
            ->assertJsonPath('data.cpu_loads', [['index' => 1, 'load_pct' => 12.5]]);
        // not on list rows, a map of many-core routers would bloat every page for nothing
        $this->assertArrayNotHasKey('cpu_loads', $this->getJson('/api/devices')->json('data.0'));
    }
}
