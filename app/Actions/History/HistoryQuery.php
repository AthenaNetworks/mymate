<?php

namespace App\Actions\History;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Bucketed read over one history family that picks its sources by tier (GitHub #28).
 *
 * Given a grid from HistoryGrid (which already chose the tier and snapped the bucket width to
 * it), the window is cut into contiguous segments, coarsest first:
 *
 *   [origin, 1h watermark)    from the 1h rollups   (only when the grid tier is 1h)
 *   [.., 5m watermark)        from the 5m rollups   (grid tier 1h or 5m)
 *   [.., to)                  from raw samples      (whatever the rollups haven't closed yet)
 *
 * Each segment is date_bin'd onto the same grid and reduced to sum / count / max / min, then
 * the union is reduced again per bucket. Because the partials are sums and counts the averages
 * come out weighted by sample count whichever mix of sources fed a bucket, so a window can
 * start in hourly rollups and finish in this minute's raw samples and still line up.
 *
 * A family with no watermark yet (rollups never ran, or the demo without a scheduler) just
 * reads raw end to end, the same as before rollups existed.
 */
class HistoryQuery
{
    public function __construct(private readonly HistoryTiers $tiers) {}

    /**
     * @param  array{bucketSeconds:int, origin:Carbon, tier:string}  $grid
     * @param  array<string, list<string>>  $metrics  metric => aggregates wanted ('avg', 'max', 'min')
     * @param  list<mixed>  $bindings  for $filter
     * @return list<object> one row per key and bucket: keys, `bucket` (Y-m-d H:i:s), then `{metric}` for
     *                      avg and `{metric}_max` / `{metric}_min`
     */
    public function rows(string $family, array $grid, Carbon $to, array $metrics, string $filter, array $bindings): array
    {
        [$sql, $params] = $this->sql($family, $grid, $to, $metrics, $filter, $bindings);

        return DB::select("{$sql} ORDER BY bucket", $params);
    }

    /**
     * The same as rows() but as [sql, bindings], for a caller that wants to aggregate further
     * over the per-key rows (eg the device total sums its interfaces).
     *
     * @return array{0:string, 1:list<mixed>}
     */
    public function sql(string $family, array $grid, Carbon $to, array $metrics, string $filter, array $bindings): array
    {
        $spec = HistoryFamilies::get($family);
        $keys = implode(', ', $spec['keys']);
        $interval = "{$grid['bucketSeconds']} seconds";
        $origin = $grid['origin']->format('Y-m-d H:i:s');

        $segments = [];
        $params = [];
        foreach ($this->segments($family, $grid, $to) as [$tier, $start, $end]) {
            $partials = [];
            foreach ($metrics as $metric => $aggs) {
                if ($tier === 'raw') {
                    $e = HistoryFamilies::rawExpr($family, $metric);
                    $partials[] = "sum({$e}) AS {$metric}_sum, count({$e}) AS {$metric}_cnt";
                    foreach (array_intersect($aggs, ['max', 'min']) as $agg) {
                        $partials[] = "{$agg}({$e}) AS {$metric}_{$agg}";
                    }
                } else {
                    $partials[] = "sum({$metric}_sum) AS {$metric}_sum, sum({$metric}_cnt) AS {$metric}_cnt";
                    foreach (array_intersect($aggs, ['max', 'min']) as $agg) {
                        $partials[] = "{$agg}({$metric}_{$agg}) AS {$metric}_{$agg}";
                    }
                }
            }
            $table = $tier === 'raw' ? $spec['raw'] : HistoryFamilies::rollupTable($family, $tier);
            $col = $tier === 'raw' ? 'ts' : 'bucket';
            $segments[] = "SELECT {$keys}, date_bin(?::interval, {$col}, ?::timestamp) AS gb, ".implode(', ', $partials)."
                FROM {$table}
                WHERE ({$filter}) AND {$col} >= ?::timestamp AND {$col} < ?::timestamp
                GROUP BY {$keys}, gb";
            array_push($params, $interval, $origin, ...$bindings);
            array_push($params, $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'));
        }

        $outer = [];
        foreach ($metrics as $metric => $aggs) {
            if (in_array('avg', $aggs, true)) {
                $outer[] = "sum({$metric}_sum) / NULLIF(sum({$metric}_cnt), 0) AS {$metric}";
            }
            foreach (array_intersect($aggs, ['max', 'min']) as $agg) {
                $outer[] = "{$agg}({$metric}_{$agg}) AS {$metric}_{$agg}";
            }
        }

        $sql = "SELECT {$keys}, to_char(gb, 'YYYY-MM-DD HH24:MI:SS') AS bucket, ".implode(', ', $outer)."
            FROM (".implode("\nUNION ALL\n", $segments).") u
            GROUP BY {$keys}, gb";

        return [$sql, $params];
    }

    /**
     * Which source serves which part of [origin, to).
     *
     * @return list<array{0:string, 1:Carbon, 2:Carbon}> [tier, start, end]
     */
    public function segments(string $family, array $grid, Carbon $to): array
    {
        $cursor = $grid['origin']->copy();
        $out = [];

        if ($grid['tier'] !== 'raw') {
            $marks = $this->tiers->watermarks()[$family] ?? [];
            $gridStep = HistoryTiers::step($grid['tier']);
            // coarsest tier the grid allows first, then finer ones for whatever it hasn't closed
            foreach (array_reverse(array_keys(HistoryTiers::ROLLUPS)) as $tier) {
                if (HistoryTiers::step($tier) > $gridStep || ! isset($marks[$tier])) {
                    continue;
                }
                $end = $marks[$tier]->min($to);
                if ($end->greaterThan($cursor)) {
                    $out[] = [$tier, $cursor, $end->copy()];
                    $cursor = $end->copy();
                }
            }
        }

        if ($cursor->lessThan($to)) {
            $out[] = ['raw', $cursor, $to->copy()];
        }
        if ($out === []) {
            // empty window, keep the SQL valid
            $out[] = ['raw', $cursor, $cursor->copy()];
        }

        return $out;
    }
}
