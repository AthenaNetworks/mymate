<?php

namespace App\Actions\History;

use Illuminate\Support\Carbon;

/**
 * Bucketed/downsampled history for one interface over [from, to). The
 * bucket width is chosen so the series is ~`history.max_points` points regardless of
 * the window, and each bucket averages util/bps. Short windows read raw samples, long
 * ones the 5m/1h rollups (GitHub #28), see HistoryQuery.
 * Keeps the payload chart-sized no matter how dense the raw samples are.
 */
class GetInterfaceSamples
{
    public function __construct(private readonly HistoryQuery $history) {}

    /** @return list<array{ts:string, util_in:?float, util_out:?float, bps_in:?float, bps_out:?float}> */
    public function __invoke(int $interfaceId, Carbon $from, Carbon $to): array
    {
        $rows = $this->history->rows(
            'interface', HistoryGrid::build($from, $to), $to,
            ['util_in' => ['avg'], 'util_out' => ['avg'], 'bps_in' => ['avg'], 'bps_out' => ['avg']],
            'interface_id = ?', [$interfaceId],
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
