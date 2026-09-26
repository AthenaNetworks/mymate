<?php

namespace App\Actions\Polling;

use App\Models\Device;
use App\Models\NetworkInterface;
use App\Services\Polling\DevicePollResult;
use App\Services\Polling\LiveInterfaceFrame;
use App\Services\Polling\PortStats;
use App\Services\Polling\PortStatsDriver;
use App\Services\Polling\RateCalculator;
use App\Services\Polling\ThroughputDriverFactory;
use App\Support\EngineLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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
        $driver = $this->drivers->for($device);
        $readings = $driver->sample($device);
        if ($readings === []) {
            return null;
        }

        $interfaces = $device->interfaces()->get()->keyBy('if_index');
        $now = now()->format('Y-m-d H:i:s');
        $rows = [];
        $frames = [];
        $history = [];

        // Port counters (errors / discards / packets). RouterOS brings them on every sample; an
        // SNMP driver reads them separately on the slower port-stats cadence.
        $portCounters = [];
        $portRead = false;
        $portTs = microtime(true);
        if ($driver instanceof PortStatsDriver && $this->portStatsDue($interfaces)) {
            $known = array_values(array_filter(array_keys($readings), static fn ($i) => $interfaces->has($i)));
            // Counts as a read even when it fails or the box has none of these OIDs, so a device
            // that can't answer is asked again next interval, not on every tick.
            $portRead = true;
            try {
                $portCounters = $driver->portCounters($device, $known);
                $portTs = microtime(true);
            } catch (\Throwable $e) {
                // the octets are already read, a failed counter read only costs the port stats
                EngineLog::debug('poll: port counter read failed', ['device_id' => $device->id, 'error' => $e->getMessage()]);
            }
        }

        foreach ($readings as $ifIndex => $sample) {
            $iface = $interfaces->get($ifIndex);
            if ($iface === null) {
                continue;
            }

            $counters = $sample->counters ?? ($portRead ? ($portCounters[$ifIndex] ?? []) : null);
            [$port, $portState, $portFresh] = $this->portRates($iface, $counters, $sample->counters !== null, $portTs);

            if ($sample->isDirectRate()) {
                // RouterOS monitor-traffic: bps straight from the device - no counter
                // state, no delta, no reset guard. Leave last_* null (unused here).
                $bpsIn = $sample->inBps;
                $bpsOut = $sample->outBps;
                $lastIn = $lastOut = $lastTs = $last32 = null;
            } else {
                // SNMP: rate = delta octetsx8/delta t (RateCalculator owns the reset guard).
                $dt = $iface->last_ts !== null
                    ? (float) $sample->ts - (float) $iface->last_ts->getTimestamp()
                    : 0.0;
                $bits = $sample->counter32 ? 32 : 64;
                // The stored counters are from the other width (HC walk dropped out or came
                // back): the delta means nothing, so no rate this tick and start over from here.
                $widthChanged = $iface->last_counter32 !== null && $iface->last_counter32 !== $sample->counter32;
                $bpsIn = $widthChanged ? null : $this->rates->bps($iface->last_in, $sample->inOctets, $dt, $bits);
                $bpsOut = $widthChanged ? null : $this->rates->bps($iface->last_out, $sample->outOctets, $dt, $bits);
                // Always store the new raw counters + ts (even on a reset tick) so the
                // next delta is computed from current reality.
                $lastIn = $sample->inOctets;
                $lastOut = $sample->outOctets;
                $lastTs = Carbon::createFromTimestamp((int) $sample->ts)->format('Y-m-d H:i:s');
                $last32 = $sample->counter32;
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
                'last_counter32' => $last32,
                'util_in' => $utilIn,
                'util_out' => $utilOut,
                // `interfaces.bps_*` are bigint - the SNMP path's RateCalculator returns a
                // float (delta octetsx8/delta t), so round here or Postgres rejects "1234.56" for bigint
                // and the whole batch upsert fails. (The RouterOS path returns int already;
                // `interface_samples.bps_*` are double precision, so history keeps the float.)
                'bps_in' => $bpsIn === null ? null : (int) round($bpsIn),
                'bps_out' => $bpsOut === null ? null : (int) round($bpsOut),
                // Per-port up/down from ifOperStatus; null when the driver didn't report it.
                'oper_status' => $sample->operUp === null ? null : ($sample->operUp ? 'up' : 'down'),
                // Latest port rates (per second). Carried over unchanged on a tick that didn't
                // read counters, so the upsert never blanks them between port-stats reads.
                ...$port,
                'port_counters' => $portState === null ? null : json_encode($portState),
                'updated_at' => $now,
            ];

            // History: this tick's port rates only when they were read now (a carried-over value
            // would be counted again in every sample), plus the oper status every tick.
            $history[$iface->id] = [...($portFresh ? $port : PortStats::none()), 'oper_up' => $sample->operUp];

            $frames[] = [
                'interface_id' => $iface->id,
                'device_id' => $device->id,
                'util_in' => $utilIn,
                'util_out' => $utilOut,
                'speed_mbps' => $iface->speed_mbps,
                'bps_in' => $bpsIn,
                'bps_out' => $bpsOut,
                'status' => $device->status->value,
                // port list extras (oper status flip, fresh port rates, new optical), only what changed
                ...LiveInterfaceFrame::extras($iface, $sample->operUp, $port, $portFresh),
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
            history: $history,
        );
    }

    /**
     * Port rates for one interface this tick.
     *
     * @param  array<string, int>|null  $counters  raw counters read now ([] = read but nothing came
     *                                             back), or null when there was no read this tick
     * @return array{0: array<string, ?float>, 1: ?array<string, mixed>, 2: bool} [rates, state to store, read now?]
     */
    private function portRates(NetworkInterface $iface, ?array $counters, bool $wide, float $ts): array
    {
        if ($counters === null) {
            $carried = [];
            foreach (PortStats::RATES as $name) {
                $carried[$name] = $iface->{$name};
            }

            return [$carried, $iface->port_counters, false];
        }
        if ($counters === []) {
            return [PortStats::none(), ['ts' => $ts, 'c' => []], true];
        }

        $next = PortStats::advance($this->rates, $iface->port_counters, $counters, $ts, $wide ? [] : PortStats::SNMP_COUNTER32);

        return [$next['rates'], $next['state'], true];
    }

    /**
     * Whether an SNMP device's port counters are due: never read, or the newest read is at least
     * `poll.port_stats_interval` old. Kept on the interface rows so it needs no scheduler of its
     * own and survives restarts. A couple of seconds of slack so tick jitter doesn't push every
     * read out by a whole extra tick.
     *
     * @param  Collection<int, NetworkInterface>  $interfaces
     */
    private function portStatsDue(Collection $interfaces): bool
    {
        $interval = max(1, (int) config('mymate.poll.port_stats_interval', 60));
        $newest = null;
        foreach ($interfaces as $iface) {
            $ts = $iface->port_counters['ts'] ?? null;
            if (is_numeric($ts) && ($newest === null || $ts > $newest)) {
                $newest = (float) $ts;
            }
        }

        return $newest === null || microtime(true) - $newest >= $interval - 2;
    }
}
