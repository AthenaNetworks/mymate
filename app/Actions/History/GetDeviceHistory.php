<?php

namespace App\Actions\History;

use App\Models\Device;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One generic history read for the device page (GitHub #28): any family, any of its metrics,
 * optionally narrowed to some keys (ports, sensors, probes), on the tier-aware grid every other
 * graph uses. Returns the grid, a series per key and metric with the average plus the min/max
 * the rollups keep, and legend stats (min / avg / max / last, and p95 on request).
 *
 * `aggregate` folds the keys into one series per metric, rates summed and percentages averaged,
 * which is the device total on the traffic graph.
 */
class GetDeviceHistory
{
    public function __construct(
        private readonly HistoryQuery $history,
        private readonly HistoryCatalog $catalog,
        private readonly HistoryPercentile $percentile,
    ) {}

    /**
     * @param  list<string>  $metrics  empty = every metric of the family
     * @param  list<string>  $keys  empty = every key the device has
     * @return array<string, mixed>|null null when a key isn't this device's
     */
    public function __invoke(
        Device $device, string $family, array $metrics, array $keys, Carbon $from, Carbon $to,
        ?int $points = null, bool $aggregate = false, bool $withP95 = false,
    ): ?array {
        $scope = $this->catalog->scope($family, $device);
        if ($scope === null) {
            return null;
        }
        $narrowed = $this->catalog->narrow($scope, $device, $keys);
        if ($narrowed === null) {
            return null;
        }
        [$filter, $bindings] = $narrowed;

        $metrics = $metrics === [] ? array_keys(HistoryFamilies::get($family)['metrics']) : array_values(array_unique($metrics));
        $keyed = $scope['keyExpr'] !== null;
        $aggregate = $aggregate && $keyed;

        $grid = HistoryGrid::build($from, $to, $points);
        $count = count($grid['buckets']);
        $meta = [];
        $spec = [];
        foreach ($metrics as $m) {
            $meta[$m] = $this->catalog->metricMeta($family, $m);
            // extremes don't survive summing across keys (a sum of maxima is no real peak)
            $spec[$m] = ['avg', ...($aggregate ? [] : HistoryCatalog::extras($family, $m))];
        }

        if ($aggregate) {
            [$inner, $params] = $this->history->sql($family, $grid, $to, $spec, $filter, $bindings);
            $cols = implode(', ', array_map(fn ($m) => ($meta[$m]['unit'] === '%' ? 'avg' : 'sum')."({$m}) AS {$m}", $metrics));
            $rows = DB::select("SELECT '' AS k, bucket, {$cols} FROM ({$inner}) x GROUP BY bucket ORDER BY bucket", $params);
        } else {
            $rows = $this->history->rows($family, $grid, $to, $spec, $filter, $bindings);
        }

        // Every requested key gets its series even if it's empty, so the legend doesn't jump about.
        $series = [];
        $seed = $aggregate || ! $keyed ? [''] : array_map('strval', $keys);
        foreach ($seed as $k) {
            $series[$k] = [];
        }
        foreach ($rows as $r) {
            $k = $aggregate || ! $keyed ? '' : implode('|', array_map(fn ($c) => $r->{$c}, $scope['keyCols']));
            $i = $grid['indexOf'][$r->bucket] ?? null;
            if ($i === null) {
                continue;
            }
            foreach ($metrics as $m) {
                $series[$k][$m] ??= self::blank($count, $spec[$m]);
                $series[$k][$m]['avg'][$i] = self::num($r->{$m}, $meta[$m]['unit']);
                foreach (['max', 'min'] as $agg) {
                    if (in_array($agg, $spec[$m], true)) {
                        $series[$k][$m][$agg][$i] = self::num($r->{"{$m}_{$agg}"}, $meta[$m]['unit']);
                    }
                }
            }
        }

        $p95 = null;
        if ($withP95) {
            $p95 = $this->percentile->compute(
                $family, $filter, $bindings, $aggregate || ! $keyed ? [] : $scope['keyCols'], $metrics, $from, $to,
                aggregate: $aggregate, combine: array_map(fn ($m) => $m['unit'] === '%' ? 'avg' : 'sum', $meta),
            );
        }

        $out = [];
        foreach ($series as $k => $byMetric) {
            foreach ($metrics as $m) {
                $data = $byMetric[$m] ?? self::blank($count, $spec[$m]);
                $stats = self::stats($data['avg'], $data['max'] ?? null);
                if ($p95 !== null) {
                    $src = $aggregate ? $p95['aggregate'] : ($p95['keys'][$k] ?? null);
                    $stats['p95'] = self::num($src[$m]['p95'] ?? null, $meta[$m]['unit']);
                }
                $out[] = [
                    'key' => $aggregate || ! $keyed ? null : (string) $k,
                    'metric' => $m,
                    'label' => $meta[$m]['label'],
                    'unit' => $meta[$m]['unit'],
                    'avg' => $data['avg'],
                    'max' => $data['max'] ?? null,
                    'min' => $data['min'] ?? null,
                    'stats' => $stats,
                ];
            }
        }

        return [
            'family' => $family,
            'tier' => $grid['tier'],
            'step' => $grid['bucketSeconds'],
            'origin' => $grid['origin']->format('Y-m-d H:i:s'),
            'count' => $count,
            'from' => $from->toIso8601ZuluString(),
            'to' => $to->toIso8601ZuluString(),
            'aggregate' => $aggregate,
            'p95_resolution' => $p95['resolution'] ?? null,
            'series' => $out,
        ];
    }

    /** @return array{avg: list<?float>, max?: list<?float>, min?: list<?float>} */
    private static function blank(int $count, array $aggs): array
    {
        $b = ['avg' => array_fill(0, $count, null)];
        foreach (['max', 'min'] as $agg) {
            if (in_array($agg, $aggs, true)) {
                $b[$agg] = array_fill(0, $count, null);
            }
        }

        return $b;
    }

    /**
     * Legend numbers over the plotted averages, LibreNMS style. `peak` is the highest single
     * sample from the max column, which on a long range sits well above the highest average.
     *
     * @param  list<?float>  $avg
     * @param  list<?float>|null  $max
     * @return array{min:?float, avg:?float, max:?float, last:?float, peak:?float}
     */
    public static function stats(array $avg, ?array $max): array
    {
        $vals = array_values(array_filter($avg, fn ($v) => $v !== null));
        $last = null;
        for ($i = count($avg) - 1; $i >= 0; $i--) {
            if ($avg[$i] !== null) {
                $last = $avg[$i];
                break;
            }
        }
        $peaks = $max === null ? [] : array_values(array_filter($max, fn ($v) => $v !== null));

        return [
            'min' => $vals === [] ? null : min($vals),
            'avg' => $vals === [] ? null : round(array_sum($vals) / count($vals), 3),
            'max' => $vals === [] ? null : max($vals),
            'last' => $last,
            'peak' => $peaks === [] ? null : max($peaks),
        ];
    }

    private static function num(mixed $v, ?string $unit): ?float
    {
        if ($v === null) {
            return null;
        }

        return round((float) $v, in_array($unit, ['bps', 'B', 'pps'], true) ? 0 : 3);
    }
}
