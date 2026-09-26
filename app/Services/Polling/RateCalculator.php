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
     *
     * $bits = 32 is the ifTable ifInOctets/ifOutOctets fallback (SNMPv1 has no HC counters).
     * Those wrap every few minutes on a busy port, so a backwards step is taken as a wrap the
     * same way counterRate() does it, rather than throwing the tick away.
     */
    public function bps(?int $last, int $current, float $dtSeconds, int $bits = 64): ?float
    {
        if ($bits === 32) {
            $rate = $this->counterRate($last, $current, $dtSeconds, 32);

            return $rate === null ? null : $rate * 8;
        }

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
     * Per-second rate of any SNMP/RouterOS counter (packets, errors, discards) between two reads.
     *
     * Null on the first read, no elapsed time, or a counter that went backwards and can't have
     * wrapped. A 64-bit counter never wraps in practice, so backwards there is always a reset
     * (reboot, counters cleared). A Counter32 (ifInErrors and friends, there's no HC version)
     * can wrap: we take it as a wrap when the corrected delta is under half the counter space,
     * eg 4294967000 -> 500 is 796 more, while 1000 -> 0 after a reboot would need 4.29 billion
     * errors in one poll, so that's a reset and gets null instead of a huge spike.
     */
    public function counterRate(?int $last, int $current, float $dtSeconds, int $bits = 64): ?float
    {
        if ($last === null || $dtSeconds <= 0.0) {
            return null;
        }

        $delta = $current - $last;
        if ($delta < 0) {
            if ($bits !== 32 || $last > self::COUNTER32_MAX || $current > self::COUNTER32_MAX) {
                return null;
            }
            $delta += self::COUNTER32_MAX + 1;
            if ($delta > intdiv(self::COUNTER32_MAX, 2)) {
                return null;
            }
        }

        return $delta / $dtSeconds;
    }

    public const COUNTER32_MAX = 4294967295;

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
