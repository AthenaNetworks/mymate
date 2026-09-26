<?php

namespace Tests\Unit;

use App\Services\Polling\PortStats;
use App\Services\Polling\RateCalculator;
use App\Services\Polling\RouterOsThroughputDriver;
use PHPUnit\Framework\TestCase;

class PortStatsTest extends TestCase
{
    public function test_first_read_gives_no_rates_but_keeps_the_counters(): void
    {
        $next = PortStats::advance(new RateCalculator, null, ['pkts_in' => 1_000, 'errors_in' => 3], 1_700_000_000.0);

        $this->assertSame(PortStats::none(), $next['rates']);
        $this->assertSame(['ts' => 1_700_000_000.0, 'c' => ['pkts_in' => 1_000, 'errors_in' => 3]], $next['state']);
    }

    public function test_second_read_gives_per_second_rates_and_wraps_only_counter32(): void
    {
        $prev = ['ts' => 1_700_000_000.0, 'c' => ['pkts_in' => 3_000_000_000, 'errors_in' => 4_294_967_000, 'discards_in' => 10]];
        $next = PortStats::advance(new RateCalculator, $prev, [
            'pkts_in' => 10,          // 64-bit going backwards: reset
            'errors_in' => 500,       // Counter32 wrap: 796 more
            'discards_in' => 70,      // 60 more
            'pkts_out' => 5,          // new counter, nothing to compare with yet
        ], 1_700_000_060.0, PortStats::SNMP_COUNTER32);

        $this->assertNull($next['rates']['pkts_in']);
        $this->assertEqualsWithDelta(796 / 60, $next['rates']['errors_in'], 0.0001);
        $this->assertEqualsWithDelta(1.0, $next['rates']['discards_in'], 0.0001);
        $this->assertNull($next['rates']['pkts_out']);
        $this->assertNull($next['rates']['errors_out']); // not read at all
    }

    public function test_without_the_counter32_list_every_counter_is_treated_as_64_bit(): void
    {
        // RouterOS: its counters are 64-bit, so the same drop is a reset there
        $prev = ['ts' => 1.0, 'c' => ['errors_in' => 4_294_967_000]];
        $this->assertNull(PortStats::advance(new RateCalculator, $prev, ['errors_in' => 500], 61.0)['rates']['errors_in']);
    }

    public function test_routeros_print_row_counters(): void
    {
        $row = ['name' => 'ether1', 'rx-packet' => '1200', 'tx-packet' => '900', 'rx-error' => '2', 'tx-error' => '0', 'rx-drop' => '5', 'tx-drop' => '1', 'running' => 'true'];

        $this->assertSame([
            'pkts_in' => 1200, 'pkts_out' => 900, 'errors_in' => 2, 'errors_out' => 0, 'discards_in' => 5, 'discards_out' => 1,
        ], RouterOsThroughputDriver::portCounters($row));
        // a virtual interface without the stats fields just has none
        $this->assertSame([], RouterOsThroughputDriver::portCounters(['name' => 'bridge1']));
    }
}
