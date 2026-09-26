<?php

namespace App\Services\Polling;

/**
 * Port packet / error / discard counters turned into per-second rates, the same way bps is:
 * a delta against the previous read, with RateCalculator's reset and wrap handling. Shared by
 * the SNMP and RouterOS throughput paths (the agent works its rates out itself).
 *
 * The previous read lives on the interface row as `port_counters`, {"ts": unix float, "c":
 * {name: raw counter}}, so it survives worker restarts the same way last_in/last_out do.
 */
final class PortStats
{
    /** Rate names, also the interfaces / interface_samples column names (per second). */
    public const RATES = ['pkts_in', 'pkts_out', 'errors_in', 'errors_out', 'discards_in', 'discards_out'];

    /** Over SNMP the error and discard counters only exist as Counter32 (no HC version), so they can wrap. */
    public const SNMP_COUNTER32 = ['errors_in', 'errors_out', 'discards_in', 'discards_out'];

    /**
     * Packets read from the 32-bit ifTable columns (v1, or a port with no HC packet counters) are
     * handed over under these names instead, so the stored state says which width a value came
     * from. A port that flips between the two then just gets one read with no rate, rather than
     * a delta between a 64-bit count and a wrapped 32-bit one. Always treated as Counter32.
     */
    public const NARROW = ['pkts_in' => 'pkts_in32', 'pkts_out' => 'pkts_out32'];

    /** Keep a sum of Counter32 columns inside 32 bits, so a wrap of either one still reads as one wrap. */
    public static function wrap32(int $sum): int
    {
        return $sum & RateCalculator::COUNTER32_MAX;
    }

    /**
     * @param  array{ts?: float|int, c?: array<string, int>}|null  $prev  the stored state, null on the first read
     * @param  array<string, int>  $counters  raw counters from this read, name => value
     * @param  list<string>  $counter32  names that are 32 bit and may wrap
     * @return array{rates: array<string, ?float>, state: array{ts: float, c: array<string, int>}}
     */
    public static function advance(RateCalculator $calc, ?array $prev, array $counters, float $ts, array $counter32 = []): array
    {
        $dt = isset($prev['ts']) ? $ts - (float) $prev['ts'] : 0.0;
        $last = is_array($prev['c'] ?? null) ? $prev['c'] : [];

        $rates = [];
        foreach (self::RATES as $name) {
            $key = $name;
            $bits = in_array($name, $counter32, true) ? 32 : 64;
            if (! isset($counters[$name]) && isset(self::NARROW[$name], $counters[self::NARROW[$name]])) {
                $key = self::NARROW[$name];
                $bits = 32;
            }
            $rates[$name] = isset($counters[$key])
                ? $calc->counterRate(isset($last[$key]) ? (int) $last[$key] : null, $counters[$key], $dt, $bits)
                : null;
        }

        return ['rates' => $rates, 'state' => ['ts' => $ts, 'c' => $counters]];
    }

    /** @return array<string, null> every rate as null */
    public static function none(): array
    {
        return array_fill_keys(self::RATES, null);
    }
}
