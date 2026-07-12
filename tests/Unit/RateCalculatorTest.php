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
