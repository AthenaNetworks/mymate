<?php

namespace App\Actions\History;

use App\Models\Device;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * What history a device has, and how to read it safely (the device page, GitHub #28).
 *
 * Driven off HistoryFamilies so a family added there turns up on the device page without
 * touching this class: the family's key columns say how its rows hang off a device, and the
 * rollup / raw tables say which metrics and keys actually hold data. Labels, units and the
 * graph group come from the family spec when it carries them, else from the fallback map
 * below (the one place that knows what "bps_in" should be called).
 *
 * Scoping is the security bit. Every filter this hands out is pinned to the device: either
 * `device_id = ?` on families keyed by it, or `{key} IN (SELECT id FROM {owner} WHERE
 * device_id = ?)` for families keyed by a child row (interfaces, probes). A caller can narrow
 * that further by keys, it can never widen it to another device.
 */
class HistoryCatalog
{
    /**
     * Fallback labels/units per family and metric, used where HistoryFamilies has no metadata.
     * [label, unit, group]. Group is the section of the Graphs tab it lands in.
     */
    private const FALLBACK = [
        'interface' => [
            '_family' => ['Traffic', 'traffic', 'Port'],
            'bps_in' => ['In', 'bps', 'traffic'],
            'bps_out' => ['Out', 'bps', 'traffic'],
            'util_in' => ['In utilisation', '%', 'traffic'],
            'util_out' => ['Out utilisation', '%', 'traffic'],
        ],
        'ping' => [
            '_family' => ['Latency', 'latency', null],
            'rtt_ms' => ['Latency', 'ms', 'latency'],
            'loss_pct' => ['Packet loss', '%', 'latency'],
            'jitter_ms' => ['Jitter', 'ms', 'latency'],
        ],
        'sensor' => [
            '_family' => ['Sensors', 'sensors', 'Sensor'],
            'value' => ['Value', null, 'sensors'],
        ],
        'probe' => [
            '_family' => ['Probes', 'probes', 'Probe'],
            'latency_ms' => ['Response time', 'ms', 'probes'],
            'up_pct' => ['Availability', '%', 'probes'],
        ],
        'device_metric' => [
            '_family' => ['Health', 'health', null],
            'cpu_pct' => ['CPU', '%', 'cpu'],
            'mem_used_pct' => ['Memory used', '%', 'memory'],
            'temp_c' => ['Temperature', 'C', 'temperature'],
            'signal_dbm' => ['Signal', 'dBm', 'wireless'],
            'snr_db' => ['SNR', 'dB', 'wireless'],
            'ccq_pct' => ['CCQ', '%', 'wireless'],
            'wireless_clients' => ['Wireless clients', null, 'wireless'],
            'ospf_neighbors' => ['OSPF neighbours', null, 'routing'],
        ],
    ];

    /** Child tables a family can be keyed by, key column => owning table (has a device_id). */
    private const OWNERS = [
        'interface_id' => 'interfaces',
        'probe_id' => 'probes',
    ];

    /** @var array<string, bool> */
    private static array $tableExists = [];

    public function __construct(private readonly HistoryTiers $tiers) {}

    /**
     * How $family's rows belong to $device: the SQL filter, its bindings, the key expression that
     * tells series apart (null for one-series-per-device families) and the owner table when the
     * keys are child rows. Null when the family can't be tied to a device at all, which keeps it
     * off the page rather than guessing.
     *
     * @return array{filter:string, bindings:list<mixed>, keyExpr:?string, keyCols:list<string>, owner:?string}|null
     */
    public function scope(string $family, Device $device): ?array
    {
        $keys = HistoryFamilies::get($family)['keys'];

        if (in_array('device_id', $keys, true)) {
            $sub = array_values(array_diff($keys, ['device_id']));

            return [
                'filter' => 'device_id = ?',
                'bindings' => [$device->id],
                'keyExpr' => self::keyExpr($sub),
                'keyCols' => $sub,
                'owner' => null,
            ];
        }

        $first = $keys[0];
        $owner = $this->ownerTable($family, $first);
        if ($owner === null) {
            return null;
        }

        return [
            'filter' => "{$first} IN (SELECT id FROM {$owner} WHERE device_id = ?)",
            'bindings' => [$device->id],
            'keyExpr' => self::keyExpr($keys),
            'keyCols' => $keys,
            'owner' => $owner,
        ];
    }

    /**
     * Narrow a family scope to $keys. Keys on an owned family must be ids of the device's own rows
     * (checked against the owner table), anything else is refused, so passing another device's
     * interface id is an error, not a quiet empty graph.
     *
     * @param  list<string>  $keys
     * @return array{0:string, 1:list<mixed>}|null [filter, bindings], null when a key isn't the device's
     */
    public function narrow(array $scope, Device $device, array $keys): ?array
    {
        if ($keys === [] || $scope['keyExpr'] === null) {
            return [$scope['filter'], $scope['bindings']];
        }

        if ($scope['owner'] !== null && count($scope['keyCols']) === 1) {
            if (array_filter($keys, fn ($k) => ! ctype_digit((string) $k)) !== []) {
                return null;
            }
            $ids = array_map('intval', $keys);
            $owned = DB::table($scope['owner'])->where('device_id', $device->id)->whereIn('id', $ids)->count();
            if ($owned !== count(array_unique($ids))) {
                return null;
            }
            $col = $scope['keyCols'][0];
            $marks = implode(', ', array_fill(0, count($ids), '?'));

            return ["({$scope['filter']}) AND {$col} IN ({$marks})", [...$scope['bindings'], ...$ids]];
        }

        // device_id keyed family with its own sub key (sensor_id, a storage index...). The device
        // filter already pins it, so compare as text and let an unknown key just match nothing.
        $marks = implode(', ', array_fill(0, count($keys), '?'));

        return ["({$scope['filter']}) AND ({$scope['keyExpr']})::text IN ({$marks})", [...$scope['bindings'], ...array_map('strval', $keys)]];
    }

    /**
     * Everything graphable for $device: per family, the metrics that hold data and the keys
     * (ports, sensors, probes...) they hold it for, with labels and units.
     *
     * @return list<array<string, mixed>>
     */
    public function forDevice(Device $device): array
    {
        $out = [];
        foreach (array_keys(HistoryFamilies::FAMILIES) as $family) {
            $scope = $this->scope($family, $device);
            if ($scope === null || ! $this->hasTable(HistoryFamilies::get($family)['raw'])) {
                continue;
            }
            $found = $this->discover($family, $scope);
            if ($found['metrics'] === []) {
                continue;
            }

            $meta = $this->familyMeta($family);
            $metrics = [];
            foreach (array_keys(HistoryFamilies::get($family)['metrics']) as $metric) {
                if (! isset($found['metrics'][$metric])) {
                    continue;
                }
                $metrics[] = ['metric' => $metric, ...$this->metricMeta($family, $metric), 'aggs' => ['avg', ...self::extras($family, $metric)]];
            }

            $out[] = [
                'family' => $family,
                'label' => $meta['label'],
                'group' => $meta['group'],
                'key_label' => $scope['keyExpr'] === null ? null : $meta['key_label'],
                'keyed' => $scope['keyExpr'] !== null,
                // 'interfaces' / 'probes' when the keys are the device's own child rows, so the
                // page can hang a family off the port picker without knowing its name
                'owner' => $scope['owner'],
                'metrics' => $metrics,
                'keys' => $scope['keyExpr'] === null ? null : $this->labelKeys($family, $scope, $device, array_keys($found['keys'])),
            ];
        }

        return $out;
    }

    /** @return array{label:string, unit:?string, group:string} */
    public function metricMeta(string $family, string $metric): array
    {
        $spec = HistoryFamilies::get($family);
        $def = $spec['metrics'][$metric] ?? [];
        $fallback = self::FALLBACK[$family][$metric] ?? null;

        // Metadata on the family spec wins, in whichever shape it's written: keyed on the metric's
        // own entry, or a per-family labels/units map.
        $label = (is_array($def) ? ($def['label'] ?? null) : null)
            ?? ($spec['labels'][$metric] ?? null)
            ?? $fallback[0]
            ?? Str::of($metric)->replace('_', ' ')->ucfirst()->toString();
        $unit = (is_array($def) && array_key_exists('unit', $def)) ? $def['unit']
            : ($spec['units'][$metric] ?? ($fallback !== null ? $fallback[1] : self::guessUnit($metric)));
        $group = (is_array($def) ? ($def['group'] ?? null) : null)
            ?? $fallback[2]
            ?? self::guessGroup($family, $metric);

        return ['label' => (string) $label, 'unit' => $unit === null ? null : (string) $unit, 'group' => (string) $group];
    }

    /** @return array{label:string, group:string, key_label:string} */
    public function familyMeta(string $family): array
    {
        $spec = HistoryFamilies::get($family);
        $fallback = self::FALLBACK[$family]['_family'] ?? null;

        return [
            'label' => (string) ($spec['label'] ?? $fallback[0] ?? Str::of($family)->replace('_', ' ')->title()->toString()),
            'group' => (string) ($spec['group'] ?? $fallback[1] ?? self::guessGroup($family, '')),
            'key_label' => (string) ($spec['key_label'] ?? $fallback[2] ?? 'Key'),
        ];
    }

    /**
     * The min/max aggregates a family's rollups keep for $metric. Reads both spec shapes, a plain
     * list (['max', 'min']) or an array with an 'aggs' entry.
     *
     * @return list<string>
     */
    public static function extras(string $family, string $metric): array
    {
        $def = HistoryFamilies::get($family)['metrics'][$metric] ?? [];
        $list = is_array($def) && isset($def['aggs']) ? $def['aggs'] : $def;

        return array_values(array_intersect(is_array($list) ? $list : [], ['max', 'min']));
    }

    /**
     * Which metrics and keys of $family hold data: the hourly rollups for the long past, plus raw
     * since the hourly watermark for whatever hasn't been rolled yet. Both are cheap, the rollup
     * is small and the raw read only covers the last hour or so on a healthy install.
     *
     * @return array{metrics: array<string, true>, keys: array<string, true>}
     */
    private function discover(string $family, array $scope): array
    {
        $spec = HistoryFamilies::get($family);
        $metrics = array_keys($spec['metrics']);
        $key = $scope['keyExpr'] === null ? "''" : "({$scope['keyExpr']})::text";

        $parts = [];
        $params = [];
        $rollup = HistoryFamilies::rollupTable($family, '1h');
        if ($this->hasTable($rollup)) {
            $cols = implode(', ', array_map(fn ($m) => "bool_or({$m}_cnt > 0) AS {$m}", $metrics));
            $parts[] = "SELECT {$key} AS k, {$cols} FROM {$rollup} WHERE {$scope['filter']} GROUP BY 1";
            array_push($params, ...$scope['bindings']);
        }

        $since = $this->tiers->watermarks()[$family]['1h'] ?? $this->tiers->cutoff('raw');
        $cols = implode(', ', array_map(fn ($m) => 'bool_or(('.HistoryFamilies::rawExpr($family, $m).") IS NOT NULL) AS {$m}", $metrics));
        $parts[] = "SELECT {$key} AS k, {$cols} FROM {$spec['raw']} WHERE ({$scope['filter']}) AND ts >= ?::timestamp GROUP BY 1";
        array_push($params, ...[...$scope['bindings'], $since->format('Y-m-d H:i:s')]);

        $found = ['metrics' => [], 'keys' => []];
        foreach (DB::select(implode("\nUNION ALL\n", $parts), $params) as $row) {
            $any = false;
            foreach ($metrics as $m) {
                if ($row->{$m}) {
                    $found['metrics'][$m] = true;
                    $any = true;
                }
            }
            if ($any && $row->k !== '') {
                $found['keys'][(string) $row->k] = true;
            }
        }

        return $found;
    }

    /**
     * Human labels for the keys that hold data: port names, sensor names (and their unit, which
     * beats the family's generic one), probe names. Anything else shows its raw key.
     *
     * @param  list<string>  $keys
     * @return list<array{key:string, label:string, unit?:?string, description?:?string}>
     */
    private function labelKeys(string $family, array $scope, Device $device, array $keys): array
    {
        // array keys came back as ints for numeric ids, so normalise before checking
        $keys = array_map('strval', $keys);
        $col = count($scope['keyCols']) === 1 ? $scope['keyCols'][0] : null;
        $ids = array_map('intval', array_filter($keys, 'ctype_digit'));

        $rows = match ($col) {
            'interface_id' => DB::table('interfaces')->where('device_id', $device->id)->whereIn('id', $ids)
                ->orderBy('if_index')->orderBy('name')->get(['id', 'name', 'description'])
                ->map(fn ($r) => ['key' => (string) $r->id, 'label' => $r->name, 'description' => $r->description]),
            'sensor_id' => DB::table('sensors')->whereIn('id', $ids)->orderBy('name')->get(['id', 'name', 'unit'])
                ->map(fn ($r) => ['key' => (string) $r->id, 'label' => $r->name, 'unit' => $r->unit]),
            'probe_id' => DB::table('probes')->where('device_id', $device->id)->whereIn('id', $ids)->orderBy('name')->get(['id', 'name'])
                ->map(fn ($r) => ['key' => (string) $r->id, 'label' => $r->name]),
            default => null,
        };

        if ($rows === null) {
            $plain = $keys;
            natsort($plain);

            return array_values(array_map(fn ($k) => ['key' => (string) $k, 'label' => (string) $k], $plain));
        }

        return $rows->values()->all();
    }

    /** Key expression over the non-device key columns, null when there are none. */
    private static function keyExpr(array $cols): ?string
    {
        return match (count($cols)) {
            0 => null,
            1 => $cols[0],
            default => "concat_ws('|', ".implode(', ', $cols).')',
        };
    }

    /**
     * The table rows of $family's first key live in, when it has a device_id column. Explicit map
     * first, then the family spec's own 'owner', then the obvious plural ("storage_id" ->
     * "storages"), so a new child-keyed family works as long as its table is named plainly.
     */
    private function ownerTable(string $family, string $key): ?string
    {
        $candidates = array_filter([
            self::OWNERS[$key] ?? null,
            HistoryFamilies::get($family)['owner'] ?? null,
            str_ends_with($key, '_id') ? Str::plural(Str::beforeLast($key, '_id')) : null,
        ]);
        foreach ($candidates as $table) {
            if ($this->hasTable($table) && Schema::hasColumn($table, 'device_id')) {
                return $table;
            }
        }

        return null;
    }

    private function hasTable(string $table): bool
    {
        return self::$tableExists[$table] ??= Schema::hasTable($table);
    }

    private static function guessUnit(string $metric): ?string
    {
        return match (true) {
            str_ends_with($metric, '_pct') => '%',
            str_ends_with($metric, '_ms') => 'ms',
            str_ends_with($metric, '_dbm') => 'dBm',
            str_ends_with($metric, '_db') => 'dB',
            str_starts_with($metric, 'bps') || str_ends_with($metric, '_bps') => 'bps',
            str_ends_with($metric, '_c') => 'C',
            str_ends_with($metric, '_bytes') => 'B',
            str_ends_with($metric, '_seconds') || str_ends_with($metric, '_s') => 's',
            str_ends_with($metric, '_pps') || str_starts_with($metric, 'pps') => 'pps',
            default => null,
        };
    }

    /** Best guess at the Graphs tab section for a family/metric nobody has described. */
    private static function guessGroup(string $family, string $metric): string
    {
        $s = strtolower("{$family} {$metric}");

        return match (true) {
            str_contains($s, 'error') || str_contains($s, 'discard') || str_contains($s, 'pkts') || str_contains($s, 'packet') => 'port_errors',
            str_contains($s, 'oper') => 'port_status',
            str_contains($s, 'optical') || str_contains($s, 'rx_dbm') || str_contains($s, 'tx_dbm') => 'optical',
            str_contains($s, 'storage') || str_contains($s, 'disk') => 'storage',
            str_contains($s, 'cpu') => 'cpu',
            str_contains($s, 'mem') => 'memory',
            str_contains($s, 'temp') => 'temperature',
            str_contains($s, 'uptime') => 'uptime',
            str_contains($s, 'wireless') || str_contains($s, 'signal') || str_contains($s, 'snr') || str_contains($s, 'ccq') => 'wireless',
            str_contains($s, 'bps') || str_contains($s, 'traffic') => 'traffic',
            default => 'other',
        };
    }
}
