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
 *
 * This is also the single source of truth for anything that reads history generically (the
 * device page graphs, HistoryCatalog): `label` and `entity` describe the family and what its
 * keys point at, `group` / `key_label` / `owner` are for the page's catalog (the Graphs tab
 * section, what one key is called, and the table a child key's rows live in), and `meta` gives
 * every metric a label, a unit, a kind and a group. Units are one of bps, pps, pct, dBm, dB, s,
 * bytes, count, ms, C (celsius), or null when it depends on the row (a custom sensor). Kinds:
 *   gauge         a reading at poll time (cpu %, signal, disk used)
 *   rate          per second, worked out from counter deltas between two polls (bps, errors/s)
 *   availability  0 or 100 per sample, so the average over a bucket is "% of the time up"
 *
 * Keys and metrics, for the families added for the device page:
 *   interface  interface_id   pkts_in/out, errors_in/out, discards_in/out (per second),
 *                             up_pct (ifOperStatus as % up)
 *   optical    interface_id   rx_dbm, tx_dbm (SFP light level, on the metrics cadence)
 *   cpu        device_id, cpu_index   load_pct (hrProcessorLoad per processor)
 *   storage    storage_id, device_id  used_pct, used_bytes, size_bytes (device_storages row)
 *   device_metric  device_id   uptime_s alongside cpu/mem/temp/RF (min per bucket shows a reboot)
 */
class HistoryFamilies
{
    public const FAMILIES = [
        'interface' => [
            'label' => 'Ports',
            'entity' => 'interface',
            'group' => 'traffic',
            'key_label' => 'Port',
            'raw' => 'interface_samples',
            'keys' => ['interface_id'],
            'metrics' => [
                'bps_in' => ['max'],
                'bps_out' => ['max'],
                'util_in' => ['max'],
                'util_out' => ['max'],
                // Port counters, as rates. Only written on the port-stats cadence for SNMP
                // (mymate.poll.port_stats_interval), every tick for RouterOS, so a raw row can
                // have bps but null here. The count in the rollup takes care of that.
                'pkts_in' => ['max'],
                'pkts_out' => ['max'],
                'errors_in' => ['max'],
                'errors_out' => ['max'],
                'discards_in' => ['max'],
                'discards_out' => ['max'],
                'up_pct' => ['min'],
            ],
            'exprs' => [
                // ifOperStatus per poll, averaged it's the percent of polls the port was up
                'up_pct' => 'CASE WHEN oper_up THEN 100.0 WHEN NOT oper_up THEN 0.0 END',
            ],
            'meta' => [
                'bps_in' => ['label' => 'Traffic in', 'unit' => 'bps', 'kind' => 'rate', 'group' => 'traffic'],
                'bps_out' => ['label' => 'Traffic out', 'unit' => 'bps', 'kind' => 'rate', 'group' => 'traffic'],
                'util_in' => ['label' => 'Utilisation in', 'unit' => 'pct', 'kind' => 'rate', 'group' => 'traffic'],
                'util_out' => ['label' => 'Utilisation out', 'unit' => 'pct', 'kind' => 'rate', 'group' => 'traffic'],
                'pkts_in' => ['label' => 'In packets', 'unit' => 'pps', 'kind' => 'rate', 'group' => 'traffic'],
                'pkts_out' => ['label' => 'Out packets', 'unit' => 'pps', 'kind' => 'rate', 'group' => 'traffic'],
                'errors_in' => ['label' => 'In errors', 'unit' => 'pps', 'kind' => 'rate', 'group' => 'port_errors'],
                'errors_out' => ['label' => 'Out errors', 'unit' => 'pps', 'kind' => 'rate', 'group' => 'port_errors'],
                'discards_in' => ['label' => 'In discards', 'unit' => 'pps', 'kind' => 'rate', 'group' => 'port_errors'],
                'discards_out' => ['label' => 'Out discards', 'unit' => 'pps', 'kind' => 'rate', 'group' => 'port_errors'],
                'up_pct' => ['label' => 'Port up', 'unit' => 'pct', 'kind' => 'availability', 'group' => 'port_status'],
            ],
        ],
        'ping' => [
            'label' => 'Latency',
            'entity' => 'device',
            'group' => 'latency',
            'raw' => 'ping_samples',
            'keys' => ['device_id'],
            'metrics' => [
                'rtt_ms' => ['max', 'min'],
                'loss_pct' => ['max'],
                'jitter_ms' => ['max'],
            ],
            'meta' => [
                'rtt_ms' => ['label' => 'Round trip', 'unit' => 'ms', 'kind' => 'gauge', 'group' => 'latency'],
                'loss_pct' => ['label' => 'Packet loss', 'unit' => 'pct', 'kind' => 'gauge', 'group' => 'latency'],
                'jitter_ms' => ['label' => 'Jitter', 'unit' => 'ms', 'kind' => 'gauge', 'group' => 'latency'],
            ],
        ],
        'sensor' => [
            'label' => 'Sensors',
            'entity' => 'sensor',
            'group' => 'sensors',
            'key_label' => 'Sensor',
            'raw' => 'sensor_samples',
            'keys' => ['sensor_id', 'device_id'],
            'metrics' => [
                'value' => ['max', 'min'],
            ],
            'meta' => [
                // the unit lives on the sensor row, it's whatever the operator set up
                'value' => ['label' => 'Value', 'unit' => null, 'kind' => 'gauge', 'group' => 'sensors'],
            ],
        ],
        'probe' => [
            'label' => 'Probes',
            'entity' => 'probe',
            'group' => 'probes',
            'key_label' => 'Probe',
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
            'meta' => [
                'latency_ms' => ['label' => 'Response time', 'unit' => 'ms', 'kind' => 'gauge', 'group' => 'probes'],
                'up_pct' => ['label' => 'Availability', 'unit' => 'pct', 'kind' => 'availability', 'group' => 'probes'],
            ],
        ],
        'device_metric' => [
            'label' => 'Health',
            'entity' => 'device',
            'group' => 'health',
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
                // min over a bucket drops right down when the box rebooted inside it
                'uptime_s' => ['max', 'min'],
            ],
            'meta' => [
                'cpu_pct' => ['label' => 'CPU', 'unit' => 'pct', 'kind' => 'gauge', 'group' => 'cpu'],
                'mem_used_pct' => ['label' => 'Memory used', 'unit' => 'pct', 'kind' => 'gauge', 'group' => 'memory'],
                'temp_c' => ['label' => 'Temperature', 'unit' => 'C', 'kind' => 'gauge', 'group' => 'temperature'],
                'signal_dbm' => ['label' => 'Signal', 'unit' => 'dBm', 'kind' => 'gauge', 'group' => 'wireless'],
                'snr_db' => ['label' => 'SNR', 'unit' => 'dB', 'kind' => 'gauge', 'group' => 'wireless'],
                'ccq_pct' => ['label' => 'CCQ', 'unit' => 'pct', 'kind' => 'gauge', 'group' => 'wireless'],
                'wireless_clients' => ['label' => 'Wireless clients', 'unit' => 'count', 'kind' => 'gauge', 'group' => 'wireless'],
                'ospf_neighbors' => ['label' => 'OSPF neighbours', 'unit' => 'count', 'kind' => 'gauge', 'group' => 'routing'],
                'uptime_s' => ['label' => 'Uptime', 'unit' => 's', 'kind' => 'gauge', 'group' => 'uptime'],
            ],
        ],
        'optical' => [
            'label' => 'Optical power',
            'entity' => 'interface',
            'group' => 'optical',
            'key_label' => 'Port',
            'raw' => 'optical_samples',
            'keys' => ['interface_id'],
            'metrics' => [
                'rx_dbm' => ['max', 'min'],
                'tx_dbm' => ['max', 'min'],
            ],
            'meta' => [
                'rx_dbm' => ['label' => 'Rx power', 'unit' => 'dBm', 'kind' => 'gauge', 'group' => 'optical'],
                'tx_dbm' => ['label' => 'Tx power', 'unit' => 'dBm', 'kind' => 'gauge', 'group' => 'optical'],
            ],
        ],
        'cpu' => [
            'label' => 'Processors',
            'entity' => 'device',
            'group' => 'cpu',
            'key_label' => 'Processor',
            'raw' => 'cpu_samples',
            'keys' => ['device_id', 'cpu_index'],
            'metrics' => [
                'load_pct' => ['max'],
            ],
            'meta' => [
                'load_pct' => ['label' => 'Load', 'unit' => 'pct', 'kind' => 'gauge', 'group' => 'cpu'],
            ],
        ],
        'storage' => [
            'label' => 'Storage',
            'entity' => 'storage',
            'group' => 'storage',
            'key_label' => 'Storage',
            'owner' => 'device_storages',
            'raw' => 'storage_samples',
            'keys' => ['storage_id', 'device_id'],
            'metrics' => [
                'used_pct' => ['max'],
                'used_bytes' => ['max'],
                'size_bytes' => ['max'],
            ],
            'meta' => [
                'used_pct' => ['label' => 'Used', 'unit' => 'pct', 'kind' => 'gauge', 'group' => 'storage'],
                'used_bytes' => ['label' => 'Used', 'unit' => 'bytes', 'kind' => 'gauge', 'group' => 'storage'],
                'size_bytes' => ['label' => 'Size', 'unit' => 'bytes', 'kind' => 'gauge', 'group' => 'storage'],
            ],
        ],
    ];

    /** @return array{raw:string, keys:list<string>, metrics:array<string,list<string>>, exprs?:array<string,string>, label?:string, entity?:string, group?:string, key_label?:string, owner?:string, meta?:array<string,array{label:string,unit:?string,kind:string,group:string}>} */
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

    /** @return list<string> every raw samples table, in family order */
    public static function rawTables(): array
    {
        return array_values(array_unique(array_column(self::FAMILIES, 'raw')));
    }

    /** @return array{label:string, unit:?string, kind:string, group:string} */
    public static function metricMeta(string $family, string $metric): array
    {
        $spec = self::get($family);
        if (! isset($spec['metrics'][$metric])) {
            throw new \InvalidArgumentException("Unknown metric [{$metric}] in history family [{$family}]");
        }

        return ($spec['meta'][$metric] ?? []) + ['label' => $metric, 'unit' => null, 'kind' => 'gauge', 'group' => 'other'];
    }

    /**
     * The whole registry in a shape an API can hand straight to the frontend: per family its
     * label, what the keys refer to, and each metric with label/unit/kind and the aggregates a
     * reader can ask for (avg always, plus whatever the rollups keep).
     *
     * @return array<string, array{label:string, entity:string, keys:list<string>, metrics:array<string, array{label:string, unit:?string, kind:string, group:string, aggregates:list<string>}>}>
     */
    public static function describe(): array
    {
        $out = [];
        foreach (self::FAMILIES as $family => $spec) {
            $metrics = [];
            foreach ($spec['metrics'] as $metric => $extra) {
                $metrics[$metric] = self::metricMeta($family, $metric) + ['aggregates' => ['avg', ...$extra]];
            }
            $out[$family] = [
                'label' => $spec['label'] ?? $family,
                'entity' => $spec['entity'] ?? 'device',
                'keys' => $spec['keys'],
                'metrics' => $metrics,
            ];
        }

        return $out;
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
