<?php

namespace App\Actions\History;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Bucketed/downsampled cpu/mem/temp history for one device over [from, to). Mirrors
 * GetInterfaceSamples: the bucket width targets ~`history.max_points` points regardless
 * of window, each bucket averaged via Postgres `date_bin` (PG14+).
 */
class GetDeviceMetricSamples
{
    /** @return list<array{ts:string, cpu_pct:?float, mem_used_pct:?float, temp_c:?float}> */
    public function __invoke(int $deviceId, Carbon $from, Carbon $to): array
    {
        $maxPoints = max(1, (int) config('mymate.history.max_points', 240));
        $span = max(1, $from->diffInSeconds($to));
        $bucketSeconds = max(10, (int) ceil($span / $maxPoints));

        $fromStr = $from->format('Y-m-d H:i:s');
        $toStr = $to->format('Y-m-d H:i:s');

        $rows = DB::select(
            <<<'SQL'
                SELECT date_bin(?::interval, ts, ?::timestamp) AS bucket,
                       avg(cpu_pct)      AS cpu_pct,
                       avg(mem_used_pct) AS mem_used_pct,
                       avg(temp_c)       AS temp_c
                FROM device_metric_samples
                WHERE device_id = ? AND ts >= ?::timestamp AND ts < ?::timestamp
                GROUP BY bucket
                ORDER BY bucket
            SQL,
            ["{$bucketSeconds} seconds", $fromStr, $deviceId, $fromStr, $toStr],
        );

        return array_map(static fn ($r): array => [
            'ts' => $r->bucket,
            'cpu_pct' => self::num($r->cpu_pct, 2),
            'mem_used_pct' => self::num($r->mem_used_pct, 2),
            'temp_c' => self::num($r->temp_c, 1),
        ], $rows);
    }

    private static function num(mixed $value, int $precision): ?float
    {
        return $value === null ? null : round((float) $value, $precision);
    }
}
