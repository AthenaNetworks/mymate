<?php

namespace Tests\Unit;

use App\Services\Polling\RateCalculator;
use PHPUnit\Framework\TestCase;

class RateCalculatorTest extends TestCase
{
    private RateCalculator $rates;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rates = new RateCalculator;
    }

    public function test_computes_bits_per_second_from_a_normal_delta(): void
    {
        // 1_250_000 octets in 10s = 10_000_000 bits / 10 = 1_000_000 bps.
        $this->assertEqualsWithDelta(1_000_000.0, $this->rates->bps(1_000_000, 2_250_000, 10.0), 0.001);
    }

    public function test_counter_reset_returns_null_no_spike(): void
    {
        // new < last (reboot/wrap) -> discard, don't emit a garbage spike.
        $this->assertNull($this->rates->bps(5_000_000, 1_000, 10.0));
    }

    public function test_no_prior_sample_returns_null(): void
    {
        $this->assertNull($this->rates->bps(null, 1_000, 10.0));
    }

    public function test_zero_and_negative_dt_return_null(): void
    {
        $this->assertNull($this->rates->bps(0, 1_000, 0.0));
        $this->assertNull($this->rates->bps(0, 1_000, -5.0));
    }

    public function test_counter_rate_is_a_plain_per_second_delta(): void
    {
        // 600 packets in 60s
        $this->assertEqualsWithDelta(10.0, $this->rates->counterRate(1_000, 1_600, 60.0), 0.0001);
        $this->assertSame(0.0, $this->rates->counterRate(5, 5, 60.0));
    }

    public function test_counter_rate_needs_a_previous_read_and_elapsed_time(): void
    {
        $this->assertNull($this->rates->counterRate(null, 1_000, 60.0)); // first poll
        $this->assertNull($this->rates->counterRate(0, 1_000, 0.0));
    }

    public function test_a_64_bit_counter_going_backwards_is_a_reset_not_a_wrap(): void
    {
        $this->assertNull($this->rates->counterRate(3_000_000_000, 10, 60.0));
        $this->assertNull($this->rates->counterRate(3_000_000_000, 10, 60.0, 64));
    }

    public function test_a_counter32_wraps_through_zero(): void
    {
        // 4294967000 -> 500 is 295 + 1 + 500 = 796 more, over 4s
        $this->assertEqualsWithDelta(199.0, $this->rates->counterRate(4_294_967_000, 500, 4.0, 32), 0.0001);
    }

    public function test_a_counter32_dropping_to_near_zero_after_a_reboot_is_not_a_wrap(): void
    {
        // taken as a wrap this would be ~4.29 billion errors in a minute
        $this->assertNull($this->rates->counterRate(1_000, 0, 60.0, 32));
        // and a value that can't be 32 bit at all is a reset whatever we were told
        $this->assertNull($this->rates->counterRate(5_000_000_000, 10, 60.0, 32));
    }

    public function test_util_percent_of_capacity(): void
    {
        // 100 Mbps on a 1000 Mbps link = 10%.
        $this->assertEqualsWithDelta(10.0, $this->rates->utilPercent(100_000_000.0, 1000), 0.0001);
    }

    public function test_util_percent_guards_missing_inputs(): void
    {
        $this->assertNull($this->rates->utilPercent(null, 1000));   // unknown rate
        $this->assertNull($this->rates->utilPercent(1_000.0, null)); // unknown capacity
        $this->assertNull($this->rates->utilPercent(1_000.0, 0));    // zero capacity (no divide-by-zero)
    }
}
