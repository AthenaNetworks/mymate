<?php

namespace App\Services\Polling;

use App\Models\NetworkInterface;

/**
 * The port-list side of an interface's live frame (InterfaceUtilUpdated), shared by the central
 * throughput tick and agent ingest.
 *
 * This goes out every tick for every link-bound interface on the fleet, so it only carries what's
 * new, and a key that isn't there means "no change, keep what you have":
 *  - oper_status only when it flipped since the last tick,
 *  - the port rates (pkts / errors / discards) only on a tick that read the counters (over SNMP
 *    that's once a port-stats interval, not every tick), and only the ones that moved,
 *  - optical Rx/Tx only on the first tick after the metrics poll read them.
 */
final class LiveInterfaceFrame
{
    /**
     * @param  NetworkInterface  $iface  the row as it was before this tick's write
     * @param  array<string, ?float>  $rates  this tick's port rates, keyed like PortStats::RATES
     * @param  bool  $ratesRead  whether $rates were read this tick (not carried over)
     * @return array<string, mixed>
     */
    public static function extras(NetworkInterface $iface, ?bool $operUp, array $rates, bool $ratesRead): array
    {
        $out = [];

        if ($operUp !== null) {
            $status = $operUp ? 'up' : 'down';
            if ($status !== $iface->oper_status) {
                $out['oper_status'] = $status;
            }
        }

        if ($ratesRead) {
            foreach (PortStats::RATES as $name) {
                if (! isset($rates[$name])) {
                    continue;
                }
                // Errors and discards sit at 0 nearly all the time, so compare with what the row
                // already says and leave out the ones that didn't move. That's most of the bytes.
                $rate = self::rate((float) $rates[$name]);
                if ($iface->{$name} === null || $rate !== self::rate((float) $iface->{$name})) {
                    $out[$name] = $rate;
                }
            }
        }

        // Optical is read on the metrics cadence, which doesn't touch updated_at, while every util
        // tick does. So optical_at at or after the row's last write means it's news to the frame.
        // Same-second counts, a missed reading is worse than a repeated one.
        $at = $iface->optical_at;
        if ($at !== null && ($iface->updated_at === null || $at->gte($iface->updated_at))) {
            if ($iface->optical_rx_dbm !== null) {
                $out['optical_rx_dbm'] = round($iface->optical_rx_dbm, 2);
            }
            if ($iface->optical_tx_dbm !== null) {
                $out['optical_tx_dbm'] = round($iface->optical_tx_dbm, 2);
            }
        }

        return $out;
    }

    /** Per-second rate as shown: whole numbers once it's in the hundreds, two places under that. */
    private static function rate(float $v): float|int
    {
        return abs($v) >= 100 ? (int) round($v) : round($v, 2);
    }

    /**
     * Trim a frame for the wire. device_id and status are already on the device frame it sits in,
     * util only ever shows to a decimal place and bps is whole bits, so the long float tails go.
     * A `ports` entry (a watched device's non-link port, see PollInterfaces) also drops the speed,
     * the port list already has it and only the map's edges care.
     *
     * @param  array<string, mixed>  $frame
     * @return array<string, mixed>
     */
    public static function compact(array $frame, bool $port = false): array
    {
        unset($frame['device_id'], $frame['status']);
        if ($port) {
            unset($frame['speed_mbps']);
        }
        foreach (['util_in', 'util_out'] as $k) {
            if (isset($frame[$k])) {
                $frame[$k] = round((float) $frame[$k], 2);
            }
        }
        foreach (['bps_in', 'bps_out'] as $k) {
            if (isset($frame[$k])) {
                $frame[$k] = (int) round((float) $frame[$k]);
            }
        }

        return $frame;
    }
}
