<?php

namespace Tests\Feature;

use App\Actions\Polling\PollInterfaces;
use App\Enums\PollMethod;
use App\Events\InterfaceUtilUpdated;
use App\Models\Device;
use App\Models\Link;
use App\Models\NetworkInterface;
use App\Services\Polling\InterfaceSample;
use App\Services\Polling\ThroughputDriver;
use App\Services\Polling\ThroughputDriverFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Tests\TestCase;

class PollInterfacesTest extends TestCase
{
    use RefreshDatabase;

    public const T0 = 1_700_000_000; // public: referenced from the anonymous fake driver

    /**
     * Fake driver: returns a +1_250_000-octet/10s reading for ifIndex 1..60 (-> 0.1% of
     * 1000 Mbps each), but throws for any device named "BAD" (failure-isolation probe).
     * `PollDeviceInterfaces` skips any index without a matching `NetworkInterface` row,
     * so a device from `makeDevices()` (if_index=1 only) still produces exactly one
     * frame entry - only `makeDeviceWithInterfaces()` devices see more than one.
     */
    private function bindFakeDriver(): void
    {
        $driver = new class implements ThroughputDriver
        {
            public function discover(Device $device): array
            {
                return [];
            }

            public function sample(Device $device): array
            {
                if ($device->name === 'BAD') {
                    throw new \RuntimeException('snmp boom');
                }

                $readings = [];
                for ($i = 1; $i <= 60; $i++) {
                    $readings[$i] = InterfaceSample::counters(2_250_000, 2_250_000, (float) (PollInterfacesTest::T0 + 10));
                }

                return $readings;
            }
        };

        $factory = new class($driver) extends ThroughputDriverFactory
        {
            public function __construct(private ThroughputDriver $driver) {}

            public function for(Device $device): ThroughputDriver
            {
                return $this->driver;
            }
        };

        $this->app->instance(ThroughputDriverFactory::class, $factory);
    }

    /** @return list<int> device ids */
    private function makeDevices(int $count, ?string $name = null): array
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $device = Device::factory()->create([
                'poll_method' => PollMethod::Snmp,
                'name' => $name ?? "dev-{$i}-".fake()->unique()->domainWord(),
            ]);
            NetworkInterface::factory()->for($device)->create([
                'if_index' => 1, 'speed_mbps' => 1000,
                'last_in' => 1_000_000, 'last_out' => 1_000_000,
                'last_ts' => Carbon::createFromTimestamp(self::T0),
            ]);
            $ids[] = $device->id;
        }

        return $ids;
    }

    /** Chain-link consecutive devices' if_index=1 interface so every one is link-bound. */
    private function chainLink(array $deviceIds): void
    {
        for ($i = 0; $i < count($deviceIds) - 1; $i++) {
            $a = NetworkInterface::where('device_id', $deviceIds[$i])->firstOrFail();
            $b = NetworkInterface::where('device_id', $deviceIds[$i + 1])->firstOrFail();
            Link::create([
                'a_device_id' => $deviceIds[$i], 'a_interface_id' => $a->id,
                'b_device_id' => $deviceIds[$i + 1], 'b_interface_id' => $b->id,
            ]);
        }
    }

    /** One device with `$count` interfaces (if_index 1..count). @return int device id */
    private function makeDeviceWithInterfaces(int $count, string $name): int
    {
        $device = Device::factory()->create(['poll_method' => PollMethod::Snmp, 'name' => $name]);
        for ($i = 1; $i <= $count; $i++) {
            NetworkInterface::factory()->for($device)->create([
                'if_index' => $i, 'speed_mbps' => 1000,
                'last_in' => 1_000_000, 'last_out' => 1_000_000,
                'last_ts' => Carbon::createFromTimestamp(self::T0),
            ]);
        }

        return $device->id;
    }

    /** Link every interface of one device to the matching-index interface of another. */
    private function linkAllInterfaces(int $deviceId, int $partnerDeviceId): void
    {
        $ifaces = NetworkInterface::where('device_id', $deviceId)->orderBy('if_index')->get();
        $partnerIfaces = NetworkInterface::where('device_id', $partnerDeviceId)->orderBy('if_index')->get()->values();
        foreach ($ifaces as $i => $iface) {
            Link::create([
                'a_device_id' => $deviceId, 'a_interface_id' => $iface->id,
                'b_device_id' => $partnerDeviceId, 'b_interface_id' => $partnerIfaces[$i]->id,
            ]);
        }
    }

    public function test_bulk_upserts_util_for_every_device_and_coalesces_broadcast(): void
    {
        Event::fake([InterfaceUtilUpdated::class]);
        $this->bindFakeDriver();
        $ids = $this->makeDevices(3);
        $this->chainLink($ids); // broadcast only carries link-bound interfaces

        $updated = app(PollInterfaces::class)($ids);

        $this->assertSame(3, $updated);
        foreach (NetworkInterface::all() as $iface) {
            $this->assertEqualsWithDelta(0.1, $iface->util_in, 0.0001); // persisted via one bulk upsert
        }
        // Coalesced: one event for the whole batch (cap >> 3 interfaces).
        Event::assertDispatchedTimes(InterfaceUtilUpdated::class, 1);
        Event::assertDispatched(InterfaceUtilUpdated::class, fn (InterfaceUtilUpdated $e) => count($e->devices) === 3);
    }

    public function test_broadcast_only_carries_link_bound_interfaces(): void
    {
        // Regression: the coalesced broadcast used to carry every polled interface on
        // every device - with a real fleet that eventually outgrows Reverb's message-size
        // limit, silently failing every tick (confirmed live: ~7k/day "Payload too large").
        // Only link-bound interfaces are ever rendered (UtilEdge colours edges from a
        // link's two interface ids), so narrowing to those is a pure size win.
        Event::fake([InterfaceUtilUpdated::class]);
        $this->bindFakeDriver();
        $ids = $this->makeDevices(3);
        $this->chainLink([$ids[0], $ids[1]]); // links dev0<->dev1; dev2 has no link at all

        app(PollInterfaces::class)($ids);

        Event::assertDispatched(InterfaceUtilUpdated::class, function (InterfaceUtilUpdated $e) use ($ids) {
            $byDevice = collect($e->devices)->keyBy('device_id');

            // The unlinked device is dropped entirely, not sent with an empty list.
            return $byDevice->has($ids[0]) && $byDevice->has($ids[1]) && ! $byDevice->has($ids[2]);
        });
    }

    public function test_broadcast_is_skipped_entirely_when_nothing_is_linked(): void
    {
        Event::fake([InterfaceUtilUpdated::class]);
        $this->bindFakeDriver();
        $ids = $this->makeDevices(2); // no links at all

        app(PollInterfaces::class)($ids);

        Event::assertNotDispatched(InterfaceUtilUpdated::class);
    }

    public function test_fractional_snmp_bps_is_stored_as_int_not_rejected_by_bigint(): void
    {
        // Regression: RateCalculator returns a float bps (delta octetsx8 / delta t). `interfaces.bps_*`
        // are bigint, so an un-rounded float made the whole batch upsert throw ("invalid
        // input syntax for type bigint"), freezing live polling + history accrual.
        $driver = new class implements ThroughputDriver
        {
            public function discover(Device $device): array
            {
                return [];
            }

            public function sample(Device $device): array
            {
                // +1000 octets over 7s -> (1000x8)/7 = 1142.857... bps (fractional).
                return [1 => InterfaceSample::counters(1_001_000, 1_001_000, (float) (PollInterfacesTest::T0 + 7))];
            }
        };
        $factory = new class($driver) extends ThroughputDriverFactory
        {
            public function __construct(private ThroughputDriver $driver) {}

            public function for(Device $device): ThroughputDriver
            {
                return $this->driver;
            }
        };
        $this->app->instance(ThroughputDriverFactory::class, $factory);

        $device = Device::factory()->create(['poll_method' => PollMethod::Snmp]);
        NetworkInterface::factory()->for($device)->create([
            'if_index' => 1, 'speed_mbps' => 1000,
            'last_in' => 1_000_000, 'last_out' => 1_000_000,
            'last_ts' => Carbon::createFromTimestamp(self::T0),
        ]);

        app(PollInterfaces::class)([$device->id]); // must not throw on the bigint column

        $iface = NetworkInterface::firstWhere('device_id', $device->id);
        $this->assertSame(1143, (int) $iface->bps_in); // 1142.857 rounded + persisted
        $this->assertNotNull($iface->last_ts);          // poll completed -> last_ts advanced
    }

    public function test_broadcast_is_capped_into_multiple_events(): void
    {
        // Exercises the interface-count cap specifically - the byte cap (default 6000)
        // never engages here since these tiny fake-driver frames are nowhere near it.
        config(['mymate.poll.broadcast.max_interfaces_per_event' => 2]);
        Event::fake([InterfaceUtilUpdated::class]);
        $this->bindFakeDriver();
        $ids = $this->makeDevices(5); // 5 devices x 1 interface, cap 2 -> 3 events
        $this->chainLink($ids); // broadcast only carries link-bound interfaces

        app(PollInterfaces::class)($ids);

        $events = Event::dispatched(InterfaceUtilUpdated::class);
        $this->assertCount(3, $events);
        foreach ($events as [$event]) {
            $ifaceCount = array_sum(array_map(fn ($d) => count($d['interfaces']), $event->devices));
            $this->assertLessThanOrEqual(2, $ifaceCount);
        }
    }

    public function test_broadcast_is_capped_by_byte_size_not_just_count(): void
    {
        // Regression: the interface-count cap alone doesn't bound bytes - 500 interfaces
        // at ~166 bytes each is ~83,000 bytes, 8x over Reverb's 10,000-byte default. Here
        // the count cap is effectively disabled so only the byte cap can force a split.
        config(['mymate.poll.broadcast.max_interfaces_per_event' => 1000]);
        config(['mymate.poll.broadcast.max_bytes_per_event' => 1000]);
        Event::fake([InterfaceUtilUpdated::class]);
        $this->bindFakeDriver();
        $ids = $this->makeDevices(10); // 10 devices x 1 interface - well over the byte budget in one event
        $this->chainLink($ids);

        app(PollInterfaces::class)($ids);

        $events = Event::dispatched(InterfaceUtilUpdated::class);
        $this->assertGreaterThan(1, count($events)); // the byte cap forced a split the count cap (1000) never would
        foreach ($events as [$event]) {
            $bytes = strlen(json_encode($event->broadcastWith()));
            $this->assertLessThanOrEqual(1000, $bytes); // the real dispatched payload, not just the summed frames
        }
    }

    public function test_a_devices_frame_exceeding_the_byte_budget_is_warned_and_skipped_not_sent(): void
    {
        // Regression: a single device wired via many links could itself produce a frame
        // bigger than the byte budget - sending it would just be rejected by Reverb the
        // same way as before. It must be skipped for the live broadcast (with a clear
        // warning, not silence) while unrelated devices in the same batch still broadcast.
        config(['mymate.poll.broadcast.max_bytes_per_event' => 2000]);
        Event::fake([InterfaceUtilUpdated::class]);
        $handler = new TestHandler;
        Log::channel('mymate')->getLogger()->pushHandler($handler);
        $this->bindFakeDriver();

        $big = $this->makeDeviceWithInterfaces(50, 'BIG'); // 50 x ~166 bytes ~ 8,300 bytes, way over 2000
        $bigPartner = $this->makeDeviceWithInterfaces(50, 'BIG-partner'); // link targets only, not polled
        $this->linkAllInterfaces($big, $bigPartner);

        $small = $this->makeDevices(1)[0];
        $smallPartner = $this->makeDevices(1)[0];
        $this->chainLink([$small, $smallPartner]);

        app(PollInterfaces::class)([$big, $small, $smallPartner]);

        $dispatchedDeviceIds = collect(Event::dispatched(InterfaceUtilUpdated::class))
            ->flatMap(fn ($args) => collect($args[0]->devices)->pluck('device_id'));
        $this->assertFalse($dispatchedDeviceIds->contains($big)); // never sent, not even oversized
        $this->assertTrue($dispatchedDeviceIds->contains($small)); // unrelated device unaffected - isolation held

        $this->assertTrue($handler->hasWarningThatContains('exceeds byte budget'));
        $record = collect($handler->getRecords())->first(fn ($r) => str_contains($r->message, 'exceeds byte budget'));
        $this->assertSame($big, $record->context['device_id']);
        $this->assertSame(50, $record->context['interfaces']);
    }

    public function test_one_failing_device_does_not_sink_the_batch(): void
    {
        Event::fake([InterfaceUtilUpdated::class]);
        $this->bindFakeDriver();
        $good = $this->makeDevices(2);
        $bad = $this->makeDevices(1, 'BAD'); // driver throws for this one

        $updated = app(PollInterfaces::class)([...$good, ...$bad]);

        $this->assertSame(2, $updated); // BAD skipped, good devices still processed
        $badIface = NetworkInterface::where('device_id', $bad[0])->first();
        $this->assertNull($badIface->util_in); // untouched
    }

    public function test_respects_broadcast_disabled(): void
    {
        config(['mymate.poll.broadcast.enabled' => false]);
        Event::fake([InterfaceUtilUpdated::class]);
        $this->bindFakeDriver();
        $ids = $this->makeDevices(2);

        $updated = app(PollInterfaces::class)($ids);

        $this->assertSame(2, $updated);          // still polled + persisted
        Event::assertNotDispatched(InterfaceUtilUpdated::class);
    }

    public function test_device_failures_are_logged_to_the_engine_channel_with_context(): void
    {
        $handler = new TestHandler;
        Log::channel('mymate')->getLogger()->pushHandler($handler);
        $this->bindFakeDriver();
        $bad = $this->makeDevices(1, 'BAD');

        app(PollInterfaces::class)($bad);

        $this->assertTrue($handler->hasWarningThatContains('device poll failed'));
        $record = collect($handler->getRecords())
            ->first(fn ($r) => str_contains($r->message, 'device poll failed'));
        $this->assertSame($bad[0], $record->context['device_id']);
        $this->assertSame('snmp boom', $record->context['error']); // exception message surfaced, no secrets
    }

    public function test_appends_a_history_sample_per_polled_interface(): void
    {
        $this->bindFakeDriver();
        $ids = $this->makeDevices(2);

        app(PollInterfaces::class)($ids);

        $this->assertSame(2, DB::table('interface_samples')->count());
        $sample = DB::table('interface_samples')->first();
        $this->assertEqualsWithDelta(0.1, (float) $sample->util_in, 0.0001); // same value persisted live
        $this->assertNotNull($sample->ts);
    }

    public function test_skips_history_for_fully_empty_ticks(): void
    {
        $this->bindFakeDriver();
        // A baseline interface (no last_ts) yields a null first delta -> null bps AND null
        // util -> no sample, so graphs don't show a fake zero.
        $device = Device::factory()->create(['poll_method' => PollMethod::Snmp, 'name' => 'fresh-'.fake()->unique()->domainWord()]);
        NetworkInterface::factory()->for($device)->create([
            'if_index' => 1, 'speed_mbps' => 1000, 'last_in' => null, 'last_out' => null, 'last_ts' => null,
        ]);

        app(PollInterfaces::class)([$device->id]);

        $this->assertSame(0, DB::table('interface_samples')->count());
    }

    public function test_records_a_throughput_sample_even_without_a_known_speed(): void
    {
        $this->bindFakeDriver();
        // Speedless interface (no speed_mbps -> util null) but a real counter delta -> bps.
        // Throughput history must still accrue.
        $device = Device::factory()->create(['poll_method' => PollMethod::Snmp, 'name' => 'nospeed-'.fake()->unique()->domainWord()]);
        NetworkInterface::factory()->for($device)->create([
            'if_index' => 1, 'speed_mbps' => null,
            'last_in' => 1_000_000, 'last_out' => 1_000_000, 'last_ts' => Carbon::createFromTimestamp(self::T0),
        ]);

        app(PollInterfaces::class)([$device->id]);

        $this->assertSame(1, DB::table('interface_samples')->count());
        $sample = DB::table('interface_samples')->first();
        $this->assertNull($sample->util_in);                       // no speed -> no util
        $this->assertEqualsWithDelta(1_000_000, (int) $sample->bps_in, 1); // but throughput is recorded
    }

    public function test_history_write_can_be_disabled(): void
    {
        config(['mymate.history.enabled' => false]);
        $this->bindFakeDriver();
        $ids = $this->makeDevices(2);

        $updated = app(PollInterfaces::class)($ids);

        $this->assertSame(2, $updated); // still polled + util persisted
        $this->assertSame(0, DB::table('interface_samples')->count()); // but no history rows
    }
}
