<?php

namespace App\Actions\History;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Bucketed/downsampled history for one custom sensor on one device over [from, to).
 * Mirrors GetDeviceMetricSamples: ~`history.max_points` buckets, each averaged via `date_bin`.
 */
class GetSensorSamples
{
    /** @return list<array{ts:string, value:?float}> */
    public function __invoke(int $sensorId, int $deviceId, Carbon $from, Carbon $to): array
    {
        $maxPoints = max(1, (int) config('mymate.history.max_points', 240));
        $span = max(1, $from->diffInSeconds($to));
        $bucketSeconds = max(10, (int) ceil($span / $maxPoints));

        $fromStr = $from->format('Y-m-d H:i:s');
        $toStr = $to->format('Y-m-d H:i:s');

        $rows = DB::select(
            <<<'SQL'
                SELECT date_bin(?::interval, ts, ?::timestamp) AS bucket, avg(value) AS value
                FROM sensor_samples
                WHERE sensor_id = ? AND device_id = ? AND ts >= ?::timestamp AND ts < ?::timestamp
                GROUP BY bucket
                ORDER BY bucket
            SQL,
            ["{$bucketSeconds} seconds", $fromStr, $sensorId, $deviceId, $fromStr, $toStr],
        );

        return array_map(static fn ($r): array => [
            'ts' => $r->bucket,
            'value' => $r->value === null ? null : round((float) $r->value, 3),
        ], $rows);
    }
}
