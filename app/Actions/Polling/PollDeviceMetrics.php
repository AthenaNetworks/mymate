<?php

namespace App\Actions\Polling;

use App\Events\DeviceMetricsUpdated;
use App\Models\Device;
use App\Services\Polling\DeviceMetricsDriverFactory;
use App\Support\EngineLog;
use Illuminate\Support\Facades\DB;

/**
 * A device-metrics tick for one shard of the fleet - the cpu/mem/temp counterpart to
 * PollInterfaces. Polls each device (failures isolated), writes the latest values back
 * onto the device row (the fast path the map tile reads), appends a history sample per
 * device that produced any reading, and broadcasts the batch so tiles update live.
 *
 * Returns the number of devices that produced a reading.
 */
class PollDeviceMetrics
{
    public function __construct(
        private DeviceMetricsDriverFactory $drivers,
        private ReadOspfNeighbors $ospf,
    ) {}

    /** @param  list<int>  $deviceIds */
    public function __invoke(array $deviceIds): int
    {
        if ($deviceIds === []) {
            return 0;
        }

        $startedAt = microtime(true);
        $now = now()->format('Y-m-d H:i:s');
        $devices = Device::with(['credential', 'routerosCredential'])->whereIn('id', $deviceIds)->get();

        $frames = [];      // for the live broadcast
        $sampleRows = [];  // for history
        $failed = 0;

        foreach ($devices as $device) {
            try {
                $metrics = $this->drivers->for($device)->sample($device);
            } catch (\Throwable $e) {
                // One black-holing/erroring device must not sink the batch. Driver
                // exceptions carry host + transport error only, never credentials.
                $failed++;
                EngineLog::warning('metrics: device poll failed', [
                    'device_id' => $device->id,
                    'device' => $device->name,
                    'ip' => $device->mgmt_ip,
                    'method' => $device->poll_method->value,
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            // OSPF: the RouterOS driver fills this itself; for an SNMP-polled router with a
            // separate RouterOS-API credential attached, read it over the API here (SNMP can't
            // expose OSPF at all).
            $ospf = $metrics->ospfNeighbors;
            if ($ospf === null && $device->routerosCredential !== null) {
                $ospf = ($this->ospf)($device->mgmt_ip, $device->routerosCredential);
            }

            if ($metrics->isEmpty() && $ospf === null) {
                continue; // nothing readable - don't stamp metrics_at or write fake zeroes
            }

            // Latest values onto the device row (individually so one persist keeps the
            // others - no bulk upsert here, the metrics fleet is device-count, not
            // interface-count, so per-device updates are cheap enough).
            $device->forceFill([
                'cpu_pct' => $metrics->cpuPct,
                'mem_used_pct' => $metrics->memUsedPct,
                'temp_c' => $metrics->tempC,
                'signal_dbm' => $metrics->signalDbm,
                'snr_db' => $metrics->snrDb,
                'ccq_pct' => $metrics->ccqPct,
                'wireless_clients' => $metrics->wirelessClients,
                'ospf_neighbors' => $ospf,
                'metrics_at' => now(),
            ])->save();

            $frames[] = [
                'device_id' => $device->id,
                'cpu_pct' => $metrics->cpuPct,
                'mem_used_pct' => $metrics->memUsedPct,
                'temp_c' => $metrics->tempC,
                'signal_dbm' => $metrics->signalDbm,
                'snr_db' => $metrics->snrDb,
                'ccq_pct' => $metrics->ccqPct,
                'wireless_clients' => $metrics->wirelessClients,
                'ospf_neighbors' => $ospf,
            ];
            $sampleRows[] = [
                'device_id' => $device->id,
                'ts' => $now,
                'cpu_pct' => $metrics->cpuPct,
                'mem_used_pct' => $metrics->memUsedPct,
                'temp_c' => $metrics->tempC,
                'signal_dbm' => $metrics->signalDbm,
                'snr_db' => $metrics->snrDb,
                'ccq_pct' => $metrics->ccqPct,
                'wireless_clients' => $metrics->wirelessClients,
                'ospf_neighbors' => $ospf,
            ];
        }

        $this->recordHistory($sampleRows);
        $this->broadcast($frames);

        EngineLog::debug('metrics: batch complete', [
            'devices' => $devices->count(),
            'polled' => count($frames),
            'failed' => $failed,
            'ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return count($frames);
    }

    /**
     * Bulk-append history - one insert for the batch, best-effort (a DB hiccup or a
     * momentarily-missing partition just loses that tick's history, never breaks a poll).
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function recordHistory(array $rows): void
    {
        if ($rows === [] || ! config('mymate.history.enabled', true)) {
            return;
        }

        try {
            DB::table('device_metric_samples')->insert($rows);
        } catch (\Throwable $e) {
            EngineLog::warning('metrics: history write failed', [
                'rows' => count($rows),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** @param  list<array{device_id:int, cpu_pct:?float, mem_used_pct:?float, temp_c:?float}>  $frames */
    private function broadcast(array $frames): void
    {
        if ($frames === [] || ! config('mymate.device_metrics.broadcast', true)) {
            return;
        }

        DeviceMetricsUpdated::dispatch($frames);
    }
}
