<?php

namespace App\Actions\History;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Bucketed history for a whole DEVICE - its total throughput over [from, to). Sums
 * bps across all the device's interfaces per time bucket (and averages util, which is
 * only meaningful when every interface has a speed). Mirrors {@see GetInterfaceSamples}
 * but device-wide, so the inspector can show total throughput rather than one
 * interface's.
 */
class GetDeviceSamples
{
    /** @return list<array{ts:string, util_in:?float, util_out:?float, bps_in:?float, bps_out:?float}> */
    public function __invoke(int $deviceId, Carbon $from, Carbon $to): array
    {
        $maxPoints = max(1, (int) config('mymate.history.max_points', 240));
        $span = max(1, $from->diffInSeconds($to));
        $bucketSeconds = max(10, (int) ceil($span / $maxPoints));

        $fromStr = $from->format('Y-m-d H:i:s');
        $toStr = $to->format('Y-m-d H:i:s');

        $rows = DB::select(
            <<<'SQL'
                SELECT date_bin(?::interval, s.ts, ?::timestamp) AS bucket,
                       avg(s.util_in)  AS util_in,
                       avg(s.util_out) AS util_out,
                       sum(s.bps_in)   AS bps_in,
                       sum(s.bps_out)  AS bps_out
                FROM interface_samples s
                JOIN interfaces i ON i.id = s.interface_id
                WHERE i.device_id = ? AND s.ts >= ?::timestamp AND s.ts < ?::timestamp
                GROUP BY bucket
                ORDER BY bucket
            SQL,
            ["{$bucketSeconds} seconds", $fromStr, $deviceId, $fromStr, $toStr],
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
