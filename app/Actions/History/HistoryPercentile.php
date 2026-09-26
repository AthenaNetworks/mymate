<?php

namespace App\Actions\History;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 95th percentile over fixed 5 minute intervals, the usual burstable billing number (GitHub #28).
 *
 * The window is cut into 5 minute buckets (the 5m rollups where they reach, raw for the rest,
 * same stitching as every other reader via HistoryQuery), each bucket's average rate is one
 * sample, the samples are sorted and the top 5% thrown away. What's left at the top is the
 * 95th percentile: nearest rank, ie percentile_disc(0.95), so it's always a rate that actually
 * happened rather than an interpolation between two. A bucket with no samples at all (device
 * down, poller stopped) isn't a sample, it's not counted as zero.
 *
 * If the window starts before the 5m tier's retention there are no 5 minute rows left to read,
 * so it falls back to hourly buckets and says so (`precise` false). An hourly average smooths
 * the bursts that 95th percentile is meant to charge for, so the number comes out lower.
 *
 * The same pass returns the per-interval sum of each metric, which is what the billing view
 * turns into bytes transferred (average rate x interval length).
 */
class HistoryPercentile
{
    public function __construct(
        private readonly HistoryQuery $history,
        private readonly HistoryTiers $tiers,
    ) {}

    /** The interval the percentile is taken over for a window starting at $from. */
    public function grid(Carbon $from): array
    {
        $tier = $from->lessThan($this->tiers->cutoff('5m')) ? '1h' : '5m';
        $step = HistoryTiers::step($tier);

        return ['tier' => $tier, 'bucketSeconds' => $step, 'origin' => HistoryTiers::floorTo($from, $step)];
    }

    /**
     * @param  list<string>  $keyCols  the family's non-device key columns ([] for one series per device)
     * @param  list<string>  $metrics
     * @param  array<string, array{0:string, 1:string}>  $greatest  extra series name => the two metrics
     *                                                              to take the per-interval max of
     * @param  array<string, string>  $combine  metric => 'sum' | 'avg', how the aggregate folds keys together
     * @return array{resolution:string, precise:bool, step:int, keys:array<string, array<string, mixed>>, aggregate:?array<string, mixed>}
     */
    public function compute(
        string $family, string $filter, array $bindings, array $keyCols, array $metrics,
        Carbon $from, Carbon $to, array $greatest = [], bool $aggregate = false, array $combine = [],
    ): array {
        $grid = $this->grid($from);
        $spec = array_fill_keys($metrics, ['avg']);
        [$inner, $params] = $this->history->sql($family, $grid, $to, $spec, $filter, $bindings);

        $key = match (count($keyCols)) {
            0 => "''",
            1 => "({$keyCols[0]})::text",
            default => "concat_ws('|', ".implode(', ', $keyCols).')',
        };

        $keys = [];
        foreach (DB::select("SELECT {$key} AS k, ".$this->selects($metrics, $greatest)." FROM ({$inner}) x GROUP BY 1", $params) as $row) {
            $keys[(string) $row->k] = $this->shape($row, $metrics, $greatest);
        }

        $agg = null;
        if ($aggregate) {
            // Fold the keys together per interval first (sum of rates across the selected ports),
            // then take the percentile of that, which is not the same as summing percentiles.
            $folded = implode(', ', array_map(fn ($m) => (($combine[$m] ?? 'sum') === 'avg' ? 'avg' : 'sum')."({$m}) AS {$m}", $metrics));
            $row = DB::selectOne(
                'SELECT '.$this->selects($metrics, $greatest)." FROM (SELECT bucket, {$folded} FROM ({$inner}) x GROUP BY bucket) a",
                $params,
            );
            $agg = $row ? $this->shape($row, $metrics, $greatest) : null;
        }

        return [
            'resolution' => $grid['tier'],
            'precise' => $grid['tier'] === '5m',
            'step' => $grid['bucketSeconds'],
            'keys' => $keys,
            'aggregate' => $agg,
        ];
    }

    private function selects(array $metrics, array $greatest): string
    {
        $cols = [];
        foreach ($metrics as $m) {
            $cols[] = "percentile_disc(0.95) WITHIN GROUP (ORDER BY {$m}) AS {$m}_p95, sum({$m}) AS {$m}_sum, count({$m}) AS {$m}_n";
        }
        foreach ($greatest as $name => [$a, $b]) {
            $cols[] = "percentile_disc(0.95) WITHIN GROUP (ORDER BY greatest({$a}, {$b})) AS {$name}_p95";
        }

        return implode(', ', $cols);
    }

    /** @return array<string, mixed> */
    private function shape(object $row, array $metrics, array $greatest): array
    {
        $out = [];
        foreach ($metrics as $m) {
            $out[$m] = [
                'p95' => $row->{"{$m}_p95"} === null ? null : (float) $row->{"{$m}_p95"},
                'sum' => $row->{"{$m}_sum"} === null ? null : (float) $row->{"{$m}_sum"},
                'samples' => (int) $row->{"{$m}_n"},
            ];
        }
        foreach (array_keys($greatest) as $name) {
            $out[$name] = ['p95' => $row->{"{$name}_p95"} === null ? null : (float) $row->{"{$name}_p95"}];
        }

        return $out;
    }
}
