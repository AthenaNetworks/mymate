<?php

namespace Tests\Unit;

use App\Services\Polling\InterfaceSample;
use PHPUnit\Framework\TestCase;

class InterfaceSampleTest extends TestCase
{
    public function test_counters_sample_carries_octets_and_is_not_a_direct_rate(): void
    {
        $sample = InterfaceSample::counters(100, 200, 1_700_000_000.5);

        $this->assertSame(100, $sample->inOctets);
        $this->assertSame(200, $sample->outOctets);
        $this->assertSame(1_700_000_000.5, $sample->ts);
        $this->assertNull($sample->inBps);
        $this->assertFalse($sample->isDirectRate());
    }

    public function test_rates_sample_carries_bps_and_is_a_direct_rate(): void
    {
        $sample = InterfaceSample::rates(1_000_000.0, 250_000.0);

        $this->assertSame(1_000_000.0, $sample->inBps);
        $this->assertSame(250_000.0, $sample->outBps);
        $this->assertNull($sample->inOctets);
        $this->assertNull($sample->ts);
        $this->assertTrue($sample->isDirectRate());
    }
}
