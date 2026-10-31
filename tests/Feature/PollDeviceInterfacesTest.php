<?php

namespace Tests\Feature;

use App\Actions\Polling\PollDeviceInterfaces;
use App\Enums\PollMethod;
use App\Models\Device;
use App\Models\NetworkInterface;
use App\Services\Polling\DevicePollResult;
use App\Services\Polling\InterfaceSample;
use App\Services\Polling\ThroughputDriver;
use App\Services\Polling\ThroughputDriverFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * PollDeviceInterfaces is now pure compute: it returns the rows to upsert + frames
 * to broadcast (persistence/broadcast moved to PollInterfaces for batch scale-out).
 */
class PollDeviceInterfacesTest extends TestCase
{
    use RefreshDatabase;

    private const T0 = 1_700_000_000;

    /** Bind a factory that always returns a fake driver with scripted readings. */
    private function fakeDriver(array $readings): void
    {
        $driver = new class($readings) implements ThroughputDriver
        {
            public function __construct(private array $readings) {}

            public function discover(Device $device): array
            {
                return [];
            }

            public function sample(Device $device): array
            {
                return $this->readings;
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

    private function deviceWithInterface(array $ifaceAttrs = []): array
    {
        $device = Device::factory()->create(['poll_method' => PollMethod::Snmp]);
        $iface = NetworkInterface::factory()->for($device)->create(array_merge([
            'if_index' => 1, 'speed_mbps' => 1000,
        ], $ifaceAttrs));

        return [$device, $iface];
    }

    public function test_first_sample_returns_null_util_but_carries_new_counters(): void
    {
        [$device] = $this->deviceWithInterface(['last_in' => null, 'last_out' => null, 'last_ts' => null]);
        $this->fakeDriver([1 => InterfaceSample::counters(1_000, 2_000, (float) self::T0)]);

        $result = app(PollDeviceInterfaces::class)($device);

        $this->assertInstanceOf(DevicePollResult::class, $result);
        $this->assertNull($result->frames[0]['util_in']);          // need two samples
        $this->assertSame(1_000, $result->upsertRows[0]['last_in']); // counters carried for next delta
        $this->assertNull($result->upsertRows[0]['util_in']);
    }

    public function test_second_sample_computes_util_and_bps(): void
    {
        [$device] = $this->deviceWithInterface([
            'speed_mbps' => 1000,
            'last_in' => 1_000_000,
            'last_out' => 1_000_000,
            'last_ts' => Carbon::createFromTimestamp(self::T0),
        ]);
        // +1_250_000 octets over 10s = 1_000_000 bps = 0.1% of 1000 Mbps.
        $this->fakeDriver([1 => InterfaceSample::counters(2_250_000, 2_250_000, (float) (self::T0 + 10))]);

        $result = app(PollDeviceInterfaces::class)($device);

        $this->assertEqualsWithDelta(0.1, $result->frames[0]['util_in'], 0.0001);
        $this->assertEqualsWithDelta(1_000_000.0, $result->frames[0]['bps_in'], 0.001);
        $this->assertEqualsWithDelta(0.1, $result->upsertRows[0]['util_in'], 0.0001);
        $this->assertSame(2_250_000, $result->upsertRows[0]['last_in']);
    }

    public function test_counter_reset_yields_null_util_no_spike(): void
    {
        [$device] = $this->deviceWithInterface([
            'last_in' => 5_000_000,
            'last_out' => 5_000_000,
            'last_ts' => Carbon::createFromTimestamp(self::T0),
        ]);
        $this->fakeDriver([1 => InterfaceSample::counters(1_000, 1_000, (float) (self::T0 + 10))]);

        $result = app(PollDeviceInterfaces::class)($device);

        $this->assertNull($result->frames[0]['util_in']);
        $this->assertSame(1_000, $result->upsertRows[0]['last_in']); // resync to current reality
    }

    public function test_returns_null_when_no_discovered_interface_matches(): void
    {
        [$device] = $this->deviceWithInterface(['if_index' => 1]);
        $this->fakeDriver([99 => InterfaceSample::counters(1, 1, (float) self::T0)]);

        $this->assertNull(app(PollDeviceInterfaces::class)($device));
    }

    public function test_per_port_util_is_symmetric_against_the_snmp_port_speed(): void
    {
        // : per-port util (shown in the inspector) uses the physical
        // speed_mbps for both directions. Asymmetric circuits are modelled on the LINK
        // now (see LinkApiTest::test_link_util_is_computed_against_the_effective_speed).
        [$device] = $this->deviceWithInterface(['speed_mbps' => 1000]);
        $this->fakeDriver([1 => InterfaceSample::rates(100_000_000.0, 250_000_000.0)]);

        $result = app(PollDeviceInterfaces::class)($device);

        $this->assertEqualsWithDelta(10.0, $result->frames[0]['util_in'], 0.0001);  // 100M / 1000M
        $this->assertEqualsWithDelta(25.0, $result->frames[0]['util_out'], 0.0001); // 250M / 1000M
    }

    public function test_direct_rate_sample_uses_bps_without_counter_state(): void
    {
        // RouterOS-style direct bps: 2 Mbit/s in on a 1000 Mbps link = 0.2%.
        [$device] = $this->deviceWithInterface(['speed_mbps' => 1000]);
        $this->fakeDriver([1 => InterfaceSample::rates(2_000_000.0, 1_000_000.0)]);

        $result = app(PollDeviceInterfaces::class)($device);

        $this->assertEqualsWithDelta(0.2, $result->frames[0]['util_in'], 0.0001);
        $this->assertEqualsWithDelta(2_000_000.0, $result->frames[0]['bps_in'], 0.001);
        $this->assertNull($result->upsertRows[0]['last_in']);  // no counter state stored
        $this->assertNull($result->upsertRows[0]['last_ts']);
    }

    // GitHub #55: Cisco IOS-XE refreshes its SNMP octet counters every ~20-30s. A read that sees
    // them unchanged mustn't be a 0, with the whole jump then landing on the next read as ~2x.
    public function test_unrefreshed_counters_carry_the_last_rate_and_keep_the_baseline(): void
    {
        [$device] = $this->deviceWithInterface([
            'speed_mbps' => 100,
            'last_in' => 5_000_000, 'last_out' => 7_000_000,
            'last_ts' => Carbon::createFromTimestamp(self::T0),
            'bps_in' => 60_000_000, 'bps_out' => 40_000_000,
        ]);
        $this->fakeDriver([1 => InterfaceSample::counters(5_000_000, 7_000_000, (float) (self::T0 + 12))]);

        $result = app(PollDeviceInterfaces::class)($device);

        $row = $result->upsertRows[0];
        $this->assertSame(60_000_000, $row['bps_in']);
        $this->assertSame(40_000_000, $row['bps_out']);
        $this->assertEqualsWithDelta(60.0, $row['util_in'], 0.001);
        $this->assertSame(5_000_000, $row['last_in']);
        $this->assertSame(Carbon::createFromTimestamp(self::T0)->format('Y-m-d H:i:s.u'), $row['last_ts']);
    }

    public function test_the_next_refresh_is_spread_over_the_whole_time_since_the_baseline(): void
    {
        // Baseline kept from 24s ago (one unrefreshed read in between): 180 MB in arrived since.
        [$device] = $this->deviceWithInterface([
            'speed_mbps' => 100,
            'last_in' => 0, 'last_out' => 0,
            'last_ts' => Carbon::createFromTimestamp(self::T0),
        ]);
        $this->fakeDriver([1 => InterfaceSample::counters(180_000_000, 120_000_000, (float) (self::T0 + 24))]);

        $row = app(PollDeviceInterfaces::class)($device)->upsertRows[0];

        $this->assertSame(60_000_000, $row['bps_in']); // 180e6 * 8 / 24, not / 12
        $this->assertSame(40_000_000, $row['bps_out']);
    }

    public function test_an_idle_port_still_reads_zero_after_the_stale_window(): void
    {
        [$device] = $this->deviceWithInterface([
            'last_in' => 5_000, 'last_out' => 5_000,
            'last_ts' => Carbon::createFromTimestamp(self::T0),
            'bps_in' => 1_000, 'bps_out' => 1_000,
        ]);
        $this->fakeDriver([1 => InterfaceSample::counters(5_000, 5_000, (float) (self::T0 + 61))]);

        $row = app(PollDeviceInterfaces::class)($device)->upsertRows[0];

        $this->assertSame(0, $row['bps_in']);
        $this->assertSame(0, $row['bps_out']);
    }

    public function test_rates_use_fractional_seconds(): void
    {
        [$device, $iface] = $this->deviceWithInterface(['last_in' => 0, 'last_out' => 0]);
        // Stored and read back through the real column, so its precision is part of the test.
        \Illuminate\Support\Facades\DB::table('interfaces')->where('id', $iface->id)
            ->update(['last_ts' => Carbon::createFromTimestamp(self::T0 + 0.9)->format('Y-m-d H:i:s.u')]);
        // 1.25 MB over 10.0s (not the 10.9s a truncated last_ts would give).
        $this->fakeDriver([1 => InterfaceSample::counters(1_250_000, 1_250_000, self::T0 + 10.9)]);

        $row = app(PollDeviceInterfaces::class)($device->fresh())->upsertRows[0];

        $this->assertSame(1_000_000, $row['bps_in']);
    }
}
