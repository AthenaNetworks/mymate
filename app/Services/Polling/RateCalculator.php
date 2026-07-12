<?php

namespace App\Services\Polling;

/**
 * The single home for throughput math (pure, no I/O - fully unit-testable).
 *
 * Turns two SNMP counter samples into a bits/sec rate with a counter-reset guard,
 * and a rate into a utilisation percentage. The RouterOS driver reports
 * bps directly and skips bps(), but still uses utilPercent().
 */
class RateCalculator
{
    /**
     * bits/sec from two octet-counter samples.
     *
     * Returns null (caller emits "no data" this tick, no spike) when the rate
     * cannot be trusted:
     *  - no prior sample yet ($last === null),
     *  - non-positive elapsed time ($dtSeconds <= 0),
     *  - the counter went backwards (device reboot / 64-bit wrap) -> $current < $last.
     */
    public function bps(?int $last, int $current, float $dtSeconds): ?float
    {
        if ($last === null || $dtSeconds <= 0.0) {
            return null;
        }

        $deltaOctets = $current - $last;
        if ($deltaOctets < 0) {
            return null; // counter reset/wrap - discard rather than emit a garbage spike.
        }

        return ($deltaOctets * 8) / $dtSeconds;
    }

    /**
     * Utilisation percent of link capacity. Null when the rate is unknown or the
     * capacity is unknown/zero (speed is user-overridable for links with no clean
     * fixed rate - see polling.md).
     */
    public function utilPercent(?float $bps, ?int $speedMbps): ?float
    {
        if ($bps === null || $speedMbps === null || $speedMbps <= 0) {
            return null;
        }

        return ($bps / ($speedMbps * 1_000_000)) * 100;
    }
}
