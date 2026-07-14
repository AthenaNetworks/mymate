<?php

namespace App\Actions\History;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Bucketed/downsampled ping latency history for one device over [from, to). Mirrors
 * GetDeviceMetricSamples: the bucket width targets ~`history.max_points` points, each
 * bucket averaging rtt/loss/jitter via Postgres `date_bin` (PG14+). Averaging binary
 * per-sweep loss (0/100) across a bucket yields a real packet-loss percentage.
 */
class GetPingSamples
{
    /** @return list<array{ts:string, rtt_ms:?float, loss_pct:?float, jitter_ms:?float}> */
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
                       avg(rtt_ms)    AS rtt_ms,
                       avg(loss_pct)  AS loss_pct,
                       avg(jitter_ms) AS jitter_ms
                FROM ping_samples
                WHERE device_id = ? AND ts >= ?::timestamp AND ts < ?::timestamp
                GROUP BY bucket
                ORDER BY bucket
            SQL,
            ["{$bucketSeconds} seconds", $fromStr, $deviceId, $fromStr, $toStr],
        );

        return array_map(static fn ($r): array => [
            'ts' => $r->bucket,
            'rtt_ms' => self::num($r->rtt_ms, 2),
            'loss_pct' => self::num($r->loss_pct, 1),
            'jitter_ms' => self::num($r->jitter_ms, 2),
        ], $rows);
    }

    private static function num(mixed $value, int $precision): ?float
    {
        return $value === null ? null : round((float) $value, $precision);
    }
}
