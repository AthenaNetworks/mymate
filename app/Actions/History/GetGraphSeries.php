<?php

namespace App\Actions\History;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Bucketed history for several interfaces at once, aligned to a single shared time grid so they can
 * be drawn on one chart and summed into a combined total (GitHub #28). Same date_bin downsampling
 * as the single-interface chart, so the bucket boundaries match; one query for the whole set keeps
 * it cheap regardless of how many interfaces the graph plots.
 */
class GetGraphSeries
{
    /**
     * @param  list<int>  $interfaceIds
     * @return array{
     *     buckets: list<string>,
     *     interfaces: array<int, array{bps_in: list<?float>, bps_out: list<?float>, util_in: list<?float>, util_out: list<?float>}>
     * }
     */
    public function __invoke(array $interfaceIds, Carbon $from, Carbon $to): array
    {
        $interfaceIds = array_values(array_unique(array_map('intval', $interfaceIds)));
        if ($interfaceIds === []) {
            return ['buckets' => [], 'interfaces' => []];
        }

        $maxPoints = max(1, (int) config('mymate.history.max_points', 240));
        $span = max(1, $from->diffInSeconds($to));
        $bucketSeconds = max(10, (int) ceil($span / $maxPoints));

        // The shared x-axis: a fixed grid from `from` stepped by the bucket width. date_bin anchors
        // each interface's rows to the same boundaries, so every series lands on this grid.
        $bucketCount = (int) ceil($span / $bucketSeconds);
        $grid = [];
        $indexOf = [];
        for ($i = 0; $i < $bucketCount; $i++) {
            $ts = $from->copy()->addSeconds($i * $bucketSeconds);
            $grid[] = $ts->format('Y-m-d H:i:s');
            $indexOf[$ts->format('Y-m-d H:i:s')] = $i;
        }

        $fromStr = $from->format('Y-m-d H:i:s');
        $toStr = $to->format('Y-m-d H:i:s');
        $placeholders = implode(',', array_fill(0, count($interfaceIds), '?'));

        $rows = DB::select(
            "SELECT interface_id,
                    to_char(date_bin(?::interval, ts, ?::timestamp), 'YYYY-MM-DD HH24:MI:SS') AS bucket,
                    avg(bps_in) AS bps_in, avg(bps_out) AS bps_out,
                    avg(util_in) AS util_in, avg(util_out) AS util_out
             FROM interface_samples
             WHERE interface_id IN ({$placeholders}) AND ts >= ?::timestamp AND ts < ?::timestamp
             GROUP BY interface_id, bucket
             ORDER BY bucket",
            ["{$bucketSeconds} seconds", $fromStr, ...$interfaceIds, $fromStr, $toStr],
        );

        // Seed each interface with a null-filled series the width of the grid, then drop values in.
        $blank = array_fill(0, $bucketCount, null);
        $interfaces = [];
        foreach ($interfaceIds as $id) {
            $interfaces[$id] = ['bps_in' => $blank, 'bps_out' => $blank, 'util_in' => $blank, 'util_out' => $blank];
        }

        foreach ($rows as $row) {
            $i = $indexOf[$row->bucket] ?? null;
            if ($i === null || ! isset($interfaces[(int) $row->interface_id])) {
                continue;
            }
            $id = (int) $row->interface_id;
            $interfaces[$id]['bps_in'][$i] = self::num($row->bps_in, 0);
            $interfaces[$id]['bps_out'][$i] = self::num($row->bps_out, 0);
            $interfaces[$id]['util_in'][$i] = self::num($row->util_in, 3);
            $interfaces[$id]['util_out'][$i] = self::num($row->util_out, 3);
        }

        return ['buckets' => $grid, 'interfaces' => $interfaces];
    }

    private static function num(mixed $value, int $precision): ?float
    {
        return $value === null ? null : round((float) $value, $precision);
    }
}
