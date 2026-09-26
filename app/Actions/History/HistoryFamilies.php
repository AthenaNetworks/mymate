<?php

namespace App\Actions\History;

/**
 * The sample families that get long-term rollups (GitHub #28), and what each one stores.
 *
 * Every family has a raw daily-partitioned samples table and a rollup table per tier
 * ("{family}_rollup_5m", "{family}_rollup_1h"). A rollup row keeps, per metric, the sum and
 * count of the raw values in the bucket plus the max (and min where it's useful). Sum + count
 * is what makes the tiers safe to re-aggregate: an hour built from twelve 5 minute rows, or a
 * graph bucket built from a mix of hours and raw samples, still averages by sample count
 * instead of averaging averages. Max is kept so a short burst isn't smoothed away on a year long
 * traffic graph.
 *
 * `metrics` maps a rollup metric name to the extra aggregates it keeps (sum/cnt are always
 * there). `exprs` is for a metric that isn't a plain raw column, eg probe up/down as a percent.
 */
class HistoryFamilies
{
    public const FAMILIES = [
        'interface' => [
            'raw' => 'interface_samples',
            'keys' => ['interface_id'],
            'metrics' => [
                'bps_in' => ['max'],
                'bps_out' => ['max'],
                'util_in' => ['max'],
                'util_out' => ['max'],
            ],
        ],
        'ping' => [
            'raw' => 'ping_samples',
            'keys' => ['device_id'],
            'metrics' => [
                'rtt_ms' => ['max', 'min'],
                'loss_pct' => ['max'],
                'jitter_ms' => ['max'],
            ],
        ],
        'sensor' => [
            'raw' => 'sensor_samples',
            'keys' => ['sensor_id', 'device_id'],
            'metrics' => [
                'value' => ['max', 'min'],
            ],
        ],
        'probe' => [
            'raw' => 'probe_samples',
            'keys' => ['probe_id'],
            'metrics' => [
                'latency_ms' => ['max', 'min'],
                'up_pct' => ['min'],
            ],
            'exprs' => [
                // availability: averaging 100/0 per check gives the percent of checks that passed
                'up_pct' => 'CASE WHEN up THEN 100.0 WHEN NOT up THEN 0.0 END',
            ],
        ],
        'device_metric' => [
            'raw' => 'device_metric_samples',
            'keys' => ['device_id'],
            'metrics' => [
                'cpu_pct' => ['max'],
                'mem_used_pct' => ['max'],
                'temp_c' => ['max', 'min'],
                'signal_dbm' => ['max', 'min'],
                'snr_db' => ['max', 'min'],
                'ccq_pct' => ['min'],
                'wireless_clients' => ['max'],
                'ospf_neighbors' => ['min'],
            ],
        ],
    ];

    /** @return array{raw:string, keys:list<string>, metrics:array<string,list<string>>, exprs?:array<string,string>} */
    public static function get(string $family): array
    {
        return self::FAMILIES[$family] ?? throw new \InvalidArgumentException("Unknown history family [{$family}]");
    }

    /** The SQL a raw row contributes for $metric (a column, or the family's expression for it). */
    public static function rawExpr(string $family, string $metric): string
    {
        return self::get($family)['exprs'][$metric] ?? $metric;
    }

    public static function rollupTable(string $family, string $tier): string
    {
        return "{$family}_rollup_{$tier}";
    }

    /** @return list<string> every rollup column (after keys + bucket) for $family */
    public static function rollupColumns(string $family): array
    {
        $cols = [];
        foreach (self::get($family)['metrics'] as $metric => $extra) {
            $cols[] = "{$metric}_sum";
            $cols[] = "{$metric}_cnt";
            foreach ($extra as $agg) {
                $cols[] = "{$metric}_{$agg}";
            }
        }

        return $cols;
    }
}
