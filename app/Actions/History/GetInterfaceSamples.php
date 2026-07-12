<?php

namespace App\Actions\History;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Bucketed/downsampled history for one interface over [from, to). The
 * bucket width is chosen so the series is ~`history.max_points` points regardless of
 * the window, and each bucket averages util/bps via Postgres `date_bin` (PG14+).
 * Keeps the payload chart-sized no matter how dense the raw samples are.
 */
class GetInterfaceSamples
{
    /** @return list<array{ts:string, util_in:?float, util_out:?float, bps_in:?float, bps_out:?float}> */
    public function __invoke(int $interfaceId, Carbon $from, Carbon $to): array
    {
        $maxPoints = max(1, (int) config('mymate.history.max_points', 240));
        $span = max(1, $from->diffInSeconds($to)); // seconds, always positive
        $bucketSeconds = max(10, (int) ceil($span / $maxPoints));

        $fromStr = $from->format('Y-m-d H:i:s');
        $toStr = $to->format('Y-m-d H:i:s');

        $rows = DB::select(
            <<<'SQL'
                SELECT date_bin(?::interval, ts, ?::timestamp) AS bucket,
                       avg(util_in)  AS util_in,
                       avg(util_out) AS util_out,
                       avg(bps_in)   AS bps_in,
                       avg(bps_out)  AS bps_out
                FROM interface_samples
                WHERE interface_id = ? AND ts >= ?::timestamp AND ts < ?::timestamp
                GROUP BY bucket
                ORDER BY bucket
            SQL,
            ["{$bucketSeconds} seconds", $fromStr, $interfaceId, $fromStr, $toStr],
        );

        return array_map(static fn ($r): array => [
            'ts' => $r->bucket,
            'util_in' => self::num($r->util_in, 3),
            'util_out' => self::num($r->util_out, 3),
            'bps_in' => self::num($r->bps_in, 0),
            'bps_out' => self::num($r->bps_out, 0),
        ], $rows);
    }

    private static function num(mixed $value, int $precision): ?float
    {
        return $value === null ? null : round((float) $value, $precision);
    }
}
