<?php

namespace App\Actions\Polling;

use App\Models\Device;
use App\Services\Polling\DeviceMetrics;
use App\Support\EngineLog;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The device-page extras of a metrics reading: per-CPU load, storage entries and uptime.
 * Shared by the central metrics tick and agent ingest so both write the same rows.
 *
 *  - deviceAttributes(): what goes on the device row (cpu_loads, uptime_seconds/uptime_at), and
 *    spots a reboot (uptime went backwards) for the log. The caller saves the device, it's
 *    already saving it for cpu/mem/temp.
 *  - __invoke(): one batch's history. cpu_samples per processor; device_storages upserted to the
 *    current state (an entry that's gone from a successful read is removed) and storage_samples
 *    per entry. Inserts are one statement per table for the whole batch, and best-effort like
 *    every other history write: a DB hiccup loses a tick of history, never the poll.
 */
class RecordDeviceResources
{
    /** TimeTicks wrap at 2^32 hundredths of a second, a bit over 497 days. */
    private const TICKS_WRAP_SECONDS = 42949672;

    /** @return array<string, mixed> */
    public static function deviceAttributes(Device $device, DeviceMetrics $m, CarbonInterface $now): array
    {
        $attrs = [];
        if ($m->cpuLoads !== null && $m->cpuLoads !== []) {
            $loads = [];
            foreach ($m->cpuLoads as $index => $load) {
                $loads[] = ['index' => (int) $index, 'load_pct' => round((float) $load, 1)];
            }
            $attrs['cpu_loads'] = $loads;
        }

        if ($m->uptimeSeconds !== null) {
            if (self::rebooted($device->uptime_seconds, $device->uptime_at, $m->uptimeSeconds, $now)) {
                // No device event timeline to hang this on yet. The uptime history shows it
                // (uptime_s min per bucket drops), this just makes it findable in the log.
                EngineLog::info('metrics: device rebooted', [
                    'device_id' => $device->id,
                    'device' => $device->name,
                    'previous_uptime_s' => $device->uptime_seconds,
                    'uptime_s' => $m->uptimeSeconds,
                ]);
            }
            $attrs['uptime_seconds'] = $m->uptimeSeconds;
            $attrs['uptime_at'] = $now;
        }

        return $attrs;
    }

    /**
     * True when the uptime went backwards since the last reading, ie the box restarted. Not when
     * it's just the 32-bit TimeTicks wrapping around after ~497 days.
     */
    public static function rebooted(?int $prevUptime, ?CarbonInterface $prevAt, int $uptime, CarbonInterface $now): bool
    {
        if ($prevUptime === null || $uptime >= $prevUptime) {
            return false;
        }
        $elapsed = $prevAt !== null ? max(0, (int) $prevAt->diffInSeconds($now, true)) : 0;

        return $prevUptime + $elapsed < self::TICKS_WRAP_SECONDS - 3600;
    }

    /**
     * @param  list<array{0: Device, 1: DeviceMetrics}>  $readings
     */
    public function __invoke(array $readings, CarbonInterface $now): void
    {
        $ts = $now->format('Y-m-d H:i:s');
        $history = (bool) config('mymate.history.enabled', true);

        $cpuRows = [];
        $storageRows = [];
        $storageDevices = [];
        foreach ($readings as [$device, $m]) {
            foreach ($m->cpuLoads ?? [] as $index => $load) {
                $cpuRows[] = ['device_id' => $device->id, 'cpu_index' => (int) $index, 'ts' => $ts, 'load_pct' => (float) $load];
            }
            if ($m->storages === null) {
                continue; // not read this time, leave what we had
            }
            $storageDevices[$device->id] = [];
            foreach ($m->storages as $s) {
                if (isset($storageDevices[$device->id][$s->key])) {
                    continue;
                }
                $storageDevices[$device->id][$s->key] = true;
                $storageRows[] = [
                    'device_id' => $device->id,
                    'storage_key' => mb_substr($s->key, 0, 64),
                    'descr' => mb_substr($s->descr, 0, 255),
                    'type' => $s->type,
                    'size_bytes' => $s->sizeBytes,
                    'used_bytes' => $s->usedBytes,
                    'used_pct' => $s->usedPct(),
                    'created_at' => $ts,
                    'updated_at' => $ts,
                ];
            }
        }

        if ($history && $cpuRows !== []) {
            $this->insert('cpu_samples', $cpuRows);
        }
        if ($storageDevices === []) {
            return;
        }

        try {
            DB::transaction(function () use ($storageDevices, $storageRows): void {
                foreach ($storageDevices as $deviceId => $keys) {
                    // an entry that's gone from a good read went away (disk pulled, tmpfs unmounted)
                    DB::table('device_storages')->where('device_id', $deviceId)
                        ->whereNotIn('storage_key', array_map('strval', array_keys($keys)))->delete();
                }
                if ($storageRows !== []) {
                    DB::table('device_storages')->upsert(
                        $storageRows,
                        ['device_id', 'storage_key'],
                        ['descr', 'type', 'size_bytes', 'used_bytes', 'used_pct', 'updated_at'],
                    );
                }
            });
        } catch (\Throwable $e) {
            EngineLog::warning('metrics: storage write failed', ['devices' => count($storageDevices), 'error' => $e->getMessage()]);

            return;
        }

        if (! $history || $storageRows === []) {
            return;
        }
        $samples = [];
        foreach (DB::table('device_storages')->whereIn('device_id', array_keys($storageDevices))
            ->get(['id', 'device_id', 'used_pct', 'used_bytes', 'size_bytes']) as $row) {
            $samples[] = [
                'storage_id' => $row->id, 'device_id' => $row->device_id, 'ts' => $ts,
                'used_pct' => $row->used_pct, 'used_bytes' => $row->used_bytes, 'size_bytes' => $row->size_bytes,
            ];
        }
        $this->insert('storage_samples', $samples);
    }

    /** @param list<array<string, mixed>> $rows */
    private function insert(string $table, array $rows): void
    {
        try {
            // own transaction (a savepoint when nested), so a failed insert can't poison a caller's
            DB::transaction(function () use ($table, $rows): void {
                foreach (array_chunk($rows, 1000) as $chunk) {
                    DB::table($table)->insert($chunk);
                }
            });
        } catch (\Throwable $e) {
            EngineLog::warning('metrics: history write failed', ['table' => $table, 'rows' => count($rows), 'error' => $e->getMessage()]);
        }
    }
}
