<?php

namespace App\Actions\Polling;

use App\Models\Device;
use App\Services\Polling\DevicePollResult;
use App\Services\Polling\RateCalculator;
use App\Services\Polling\ThroughputDriverFactory;
use Illuminate\Support\Carbon;

/**
 * Compute one device's throughput tick (pure-ish: SNMP read + math, no persistence
 * and no broadcast). Turns counter deltas into bps + util% (RateCalculator owns the
 * math + reset guard) and returns the rows to upsert + frames to broadcast.
 *
 * Persistence + broadcast are the orchestrator's job ([PollInterfaces]) so a whole
 * batch is bulk-upserted in one round trip and the broadcast is coalesced (P5).
 * Interfaces not yet discovered are skipped (DiscoverInterfaces creates them).
 */
class PollDeviceInterfaces
{
    public function __construct(
        private ThroughputDriverFactory $drivers,
        private RateCalculator $rates,
    ) {}

    public function __invoke(Device $device): ?DevicePollResult
    {
        $readings = $this->drivers->for($device)->sample($device);
        if ($readings === []) {
            return null;
        }

        $interfaces = $device->interfaces()->get()->keyBy('if_index');
        $now = now()->format('Y-m-d H:i:s');
        $rows = [];
        $frames = [];

        foreach ($readings as $ifIndex => $sample) {
            $iface = $interfaces->get($ifIndex);
            if ($iface === null) {
                continue;
            }

            if ($sample->isDirectRate()) {
                // RouterOS monitor-traffic: bps straight from the device - no counter
                // state, no delta, no reset guard. Leave last_* null (unused here).
                $bpsIn = $sample->inBps;
                $bpsOut = $sample->outBps;
                $lastIn = $lastOut = $lastTs = null;
            } else {
                // SNMP: rate = delta octetsx8/delta t (RateCalculator owns the reset guard).
                $dt = $iface->last_ts !== null
                    ? (float) $sample->ts - (float) $iface->last_ts->getTimestamp()
                    : 0.0;
                $bpsIn = $this->rates->bps($iface->last_in, $sample->inOctets, $dt);
                $bpsOut = $this->rates->bps($iface->last_out, $sample->outOctets, $dt);
                // Always store the new raw counters + ts (even on a reset tick) so the
                // next delta is computed from current reality.
                $lastIn = $sample->inOctets;
                $lastOut = $sample->outOctets;
                $lastTs = Carbon::createFromTimestamp((int) $sample->ts)->format('Y-m-d H:i:s');
            }

            // Per-PORT utilisation vs the physical interface speed (shown in the device
            // inspector). The LINK's util - what colours the map edge - is computed from
            // raw bps / the link's effective speed, not from these.
            $utilIn = $this->rates->utilPercent($bpsIn, $iface->speed_mbps);
            $utilOut = $this->rates->utilPercent($bpsOut, $iface->speed_mbps);

            // `name` is included only to satisfy the upsert's insert branch - it's
            // never an update column.
            $rows[] = [
                'device_id' => $device->id,
                'if_index' => $ifIndex,
                'name' => $iface->name,
                'last_in' => $lastIn,
                'last_out' => $lastOut,
                'last_ts' => $lastTs,
                'util_in' => $utilIn,
                'util_out' => $utilOut,
                // `interfaces.bps_*` are bigint - the SNMP path's RateCalculator returns a
                // float (delta octetsx8/delta t), so round here or Postgres rejects "1234.56" for bigint
                // and the whole batch upsert fails. (The RouterOS path returns int already;
                // `interface_samples.bps_*` are double precision, so history keeps the float.)
                'bps_in' => $bpsIn === null ? null : (int) round($bpsIn),
                'bps_out' => $bpsOut === null ? null : (int) round($bpsOut),
                'updated_at' => $now,
            ];

            $frames[] = [
                'interface_id' => $iface->id,
                'device_id' => $device->id,
                'util_in' => $utilIn,
                'util_out' => $utilOut,
                'speed_mbps' => $iface->speed_mbps,
                'bps_in' => $bpsIn,
                'bps_out' => $bpsOut,
                'status' => $device->status->value,
            ];
        }

        if ($rows === []) {
            return null;
        }

        return new DevicePollResult(
            deviceId: $device->id,
            status: $device->status->value,
            upsertRows: $rows,
            frames: $frames,
        );
    }
}
