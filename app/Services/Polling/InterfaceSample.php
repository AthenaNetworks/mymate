<?php

namespace App\Services\Polling;

/**
 * One interface's reading from a throughput driver - the seam between the two
 * driver styles:
 *  - **counters** (SNMP): raw octet counters + timestamp; the rate is a delta
 *    computed downstream by RateCalculator (with the reset guard).
 *  - **direct rate** (RouterOS `monitor-traffic once`): bits/sec straight from the
 *    device - no counter state, no delta, no reset guard.
 *
 * PollDeviceInterfaces branches on isDirectRate(), so everything after the driver
 * (util %, persistence, broadcast) stays driver-agnostic.
 */
final readonly class InterfaceSample
{
    private function __construct(
        public ?int $inOctets,
        public ?int $outOctets,
        public ?float $ts,
        public ?float $inBps,
        public ?float $outBps,
        public ?bool $operUp = null, // ifOperStatus: true=up, false=down, null=not reported
    ) {}

    public static function counters(int $inOctets, int $outOctets, float $ts, ?bool $operUp = null): self
    {
        return new self($inOctets, $outOctets, $ts, null, null, $operUp);
    }

    public static function rates(float $inBps, float $outBps, ?bool $operUp = null): self
    {
        return new self(null, null, null, $inBps, $outBps, $operUp);
    }

    public function isDirectRate(): bool
    {
        return $this->inBps !== null;
    }
}
