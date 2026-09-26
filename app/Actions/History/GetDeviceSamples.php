<?php

namespace App\Actions\History;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Bucketed history for a whole DEVICE - its total throughput over [from, to). Each
 * interface is averaged per time bucket first, then bps is summed across the device's
 * interfaces (and util averaged, which is only meaningful when every interface has a
 * speed). Mirrors {@see GetInterfaceSamples} but device-wide, so the inspector can show
 * total throughput rather than one interface's. Raw or rollups by window, see HistoryQuery.
 */
class GetDeviceSamples
{
    public function __construct(private readonly HistoryQuery $history) {}

    /** @return list<array{ts:string, util_in:?float, util_out:?float, bps_in:?float, bps_out:?float}> */
    public function __invoke(int $deviceId, Carbon $from, Carbon $to): array
    {
        // Average per interface first: summing raw samples straight across a bucket would
        // count each interface once per poll that landed in it, not once.
        [$inner, $bindings] = $this->history->sql(
            'interface', HistoryGrid::build($from, $to), $to,
            ['util_in' => ['avg'], 'util_out' => ['avg'], 'bps_in' => ['avg'], 'bps_out' => ['avg']],
            'interface_id IN (SELECT id FROM interfaces WHERE device_id = ?)', [$deviceId],
        );

        $rows = DB::select(
            "SELECT bucket, avg(util_in) AS util_in, avg(util_out) AS util_out, sum(bps_in) AS bps_in, sum(bps_out) AS bps_out
             FROM ({$inner}) per_iface
             GROUP BY bucket
             ORDER BY bucket",
            $bindings,
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
