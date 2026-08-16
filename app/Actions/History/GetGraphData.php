<?php

namespace App\Actions\History;

use App\Models\Device;
use App\Models\NetworkInterface;
use App\Models\Probe;
use App\Models\Sensor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Resolve a graph's series from every source it can plot (GitHub #28), aligned to one shared time
 * grid so they draw together and the total is a clean sum:
 *  - interface  : throughput (bps) or utilisation (%), inbound or outbound
 *  - sensor     : a custom SNMP OID's value on a device
 *  - ping       : a device's ICMP round-trip latency (ms)
 *  - probe      : an HTTP/TCP service probe's response time (ms)
 *
 * Each source is batch-fetched in one bucketed query, so a graph with many series is still a
 * handful of queries regardless of how many interfaces/sensors/probes it references.
 */
class GetGraphData
{
    /**
     * @param  list<array<string,mixed>>  $configSeries
     * @return array{buckets:list<string>, series:list<array<string,mixed>>, total:?list<?float>}
     */
    public function __invoke(array $configSeries, string $metric, bool $showTotal, Carbon $from, Carbon $to): array
    {
        $grid = HistoryGrid::build($from, $to);
        $bucketCount = count($grid['buckets']);
        $blank = array_fill(0, $bucketCount, null);

        // Gather the ids each source needs.
        $interfaceIds = $sensorIds = $sensorDeviceIds = $pingDeviceIds = $probeIds = [];
        foreach ($configSeries as $s) {
            switch ($s['source'] ?? 'interface') {
                case 'sensor': $sensorIds[] = (int) ($s['sensor_id'] ?? 0); $sensorDeviceIds[] = (int) ($s['device_id'] ?? 0); break;
                case 'ping': $pingDeviceIds[] = (int) ($s['device_id'] ?? 0); break;
                case 'probe': $probeIds[] = (int) ($s['probe_id'] ?? 0); break;
                default: $interfaceIds[] = (int) ($s['interface_id'] ?? 0);
            }
        }

        $ifaceData = $this->interfaces(array_unique($interfaceIds), $grid, $from, $to);
        $sensorData = $this->sensors(array_unique($sensorIds), array_unique($sensorDeviceIds), $grid, $from, $to);
        $pingData = $this->ping(array_unique($pingDeviceIds), $grid, $from, $to);
        $probeData = $this->probes(array_unique($probeIds), $grid, $from, $to);

        // Label lookups.
        $ifaces = NetworkInterface::whereIn('id', $interfaceIds)->with('device:id,name')->get(['id', 'device_id', 'name'])->keyBy('id');
        $sensors = Sensor::whereIn('id', $sensorIds)->get(['id', 'name', 'unit'])->keyBy('id');
        $devices = Device::whereIn('id', array_merge($pingDeviceIds, $sensorDeviceIds))->get(['id', 'name'])->keyBy('id');
        $probes = Probe::whereIn('id', $probeIds)->with('device:id,name')->get(['id', 'device_id', 'name'])->keyBy('id');

        $series = [];
        foreach ($configSeries as $s) {
            $source = $s['source'] ?? 'interface';
            $built = match ($source) {
                'sensor' => $this->sensorSeries($s, $sensorData, $sensors, $devices, $blank),
                'ping' => $this->pingSeries($s, $pingData, $devices, $blank),
                'probe' => $this->probeSeries($s, $probeData, $probes, $blank),
                default => $this->interfaceSeries($s, $metric, $ifaceData, $ifaces, $blank),
            };
            if ($built !== null) {
                // Optional per-series colour override; the chart falls back to the palette on null.
                $color = $s['color'] ?? null;
                $built['color'] = is_string($color) && preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? strtolower($color) : null;
                // Optional custom name overrides the computed label.
                $name = $s['name'] ?? null;
                if (is_string($name) && trim($name) !== '') {
                    $built['label'] = trim($name);
                }
                $series[] = $built;
            }
        }

        $total = $showTotal && $series !== []
            ? $this->sum(array_map(static fn ($x) => $x['values'], $series), $bucketCount)
            : null;

        return ['buckets' => $grid['buckets'], 'series' => $series, 'total' => $total];
    }

    /** @return array<int, array{bps_in:list<?float>,bps_out:list<?float>,util_in:list<?float>,util_out:list<?float>}> */
    private function interfaces(array $ids, array $grid, Carbon $from, Carbon $to): array
    {
        $ids = array_values(array_filter($ids));
        if ($ids === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $rows = DB::select(
            "SELECT interface_id, to_char(date_bin(?::interval, ts, ?::timestamp), 'YYYY-MM-DD HH24:MI:SS') AS bucket,
                    avg(bps_in) AS bps_in, avg(bps_out) AS bps_out, avg(util_in) AS util_in, avg(util_out) AS util_out
             FROM interface_samples WHERE interface_id IN ({$ph}) AND ts >= ?::timestamp AND ts < ?::timestamp
             GROUP BY interface_id, bucket",
            ["{$grid['bucketSeconds']} seconds", $from->format('Y-m-d H:i:s'), ...$ids, $from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')],
        );
        $n = count($grid['buckets']);
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = ['bps_in' => array_fill(0, $n, null), 'bps_out' => array_fill(0, $n, null), 'util_in' => array_fill(0, $n, null), 'util_out' => array_fill(0, $n, null)];
        }
        foreach ($rows as $r) {
            $i = $grid['indexOf'][$r->bucket] ?? null;
            if ($i === null) {
                continue;
            }
            $out[(int) $r->interface_id]['bps_in'][$i] = self::num($r->bps_in, 0);
            $out[(int) $r->interface_id]['bps_out'][$i] = self::num($r->bps_out, 0);
            $out[(int) $r->interface_id]['util_in'][$i] = self::num($r->util_in, 3);
            $out[(int) $r->interface_id]['util_out'][$i] = self::num($r->util_out, 3);
        }

        return $out;
    }

    /** @return array<string, list<?float>> keyed "sensorId:deviceId" */
    private function sensors(array $sensorIds, array $deviceIds, array $grid, Carbon $from, Carbon $to): array
    {
        $sensorIds = array_values(array_filter($sensorIds));
        $deviceIds = array_values(array_filter($deviceIds));
        if ($sensorIds === [] || $deviceIds === []) {
            return [];
        }
        $sp = implode(',', array_fill(0, count($sensorIds), '?'));
        $dp = implode(',', array_fill(0, count($deviceIds), '?'));
        $rows = DB::select(
            "SELECT sensor_id, device_id, to_char(date_bin(?::interval, ts, ?::timestamp), 'YYYY-MM-DD HH24:MI:SS') AS bucket, avg(value) AS value
             FROM sensor_samples WHERE sensor_id IN ({$sp}) AND device_id IN ({$dp}) AND ts >= ?::timestamp AND ts < ?::timestamp
             GROUP BY sensor_id, device_id, bucket",
            ["{$grid['bucketSeconds']} seconds", $from->format('Y-m-d H:i:s'), ...$sensorIds, ...$deviceIds, $from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')],
        );

        return $this->keyed($rows, fn ($r) => "{$r->sensor_id}:{$r->device_id}", 'value', $grid, 3);
    }

    /** @return array<int, list<?float>> device_id => rtt series */
    private function ping(array $deviceIds, array $grid, Carbon $from, Carbon $to): array
    {
        $deviceIds = array_values(array_filter($deviceIds));
        if ($deviceIds === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($deviceIds), '?'));
        $rows = DB::select(
            "SELECT device_id, to_char(date_bin(?::interval, ts, ?::timestamp), 'YYYY-MM-DD HH24:MI:SS') AS bucket, avg(rtt_ms) AS value
             FROM ping_samples WHERE device_id IN ({$ph}) AND ts >= ?::timestamp AND ts < ?::timestamp
             GROUP BY device_id, bucket",
            ["{$grid['bucketSeconds']} seconds", $from->format('Y-m-d H:i:s'), ...$deviceIds, $from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')],
        );

        return $this->keyed($rows, fn ($r) => (int) $r->device_id, 'value', $grid, 2);
    }

    /** @return array<int, list<?float>> probe_id => latency series */
    private function probes(array $probeIds, array $grid, Carbon $from, Carbon $to): array
    {
        $probeIds = array_values(array_filter($probeIds));
        if ($probeIds === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($probeIds), '?'));
        $rows = DB::select(
            "SELECT probe_id, to_char(date_bin(?::interval, ts, ?::timestamp), 'YYYY-MM-DD HH24:MI:SS') AS bucket, avg(latency_ms) AS value
             FROM probe_samples WHERE probe_id IN ({$ph}) AND ts >= ?::timestamp AND ts < ?::timestamp
             GROUP BY probe_id, bucket",
            ["{$grid['bucketSeconds']} seconds", $from->format('Y-m-d H:i:s'), ...$probeIds, $from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')],
        );

        return $this->keyed($rows, fn ($r) => (int) $r->probe_id, 'value', $grid, 2);
    }

    /** Map single-value bucketed rows into grid-aligned arrays, keyed by the given closure. */
    private function keyed(array $rows, callable $key, string $col, array $grid, int $precision): array
    {
        $n = count($grid['buckets']);
        $out = [];
        foreach ($rows as $r) {
            $k = $key($r);
            $i = $grid['indexOf'][$r->bucket] ?? null;
            if ($i === null) {
                continue;
            }
            $out[$k] ??= array_fill(0, $n, null);
            $out[$k][$i] = self::num($r->{$col}, $precision);
        }

        return $out;
    }

    private function interfaceSeries(array $s, string $metric, array $data, $ifaces, array $blank): ?array
    {
        $id = (int) ($s['interface_id'] ?? 0);
        $direction = ($s['direction'] ?? 'in') === 'out' ? 'out' : 'in';
        $iface = $ifaces->get($id);
        if ($iface === null || ! isset($data[$id])) {
            return null;
        }
        $key = ($metric === 'util' ? 'util' : 'bps')."_{$direction}";

        return [
            'label' => trim(($iface->device?->name ? "{$iface->device->name} " : '')."{$iface->name} {$direction}"),
            'format' => $metric === 'util' ? 'util' : 'rate',
            'unit' => null,
            'dashed' => $direction === 'out',
            'group' => "if:{$id}",
            'values' => $data[$id][$key],
        ];
    }

    private function sensorSeries(array $s, array $data, $sensors, $devices, array $blank): ?array
    {
        $sid = (int) ($s['sensor_id'] ?? 0);
        $did = (int) ($s['device_id'] ?? 0);
        $sensor = $sensors->get($sid);
        if ($sensor === null) {
            return null;
        }

        return [
            'label' => trim("{$sensor->name}".($devices->get($did) ? " ({$devices->get($did)->name})" : '')),
            'format' => 'value',
            'unit' => $sensor->unit,
            'dashed' => false,
            'group' => "sensor:{$sid}:{$did}",
            'values' => $data["{$sid}:{$did}"] ?? $blank,
        ];
    }

    private function pingSeries(array $s, array $data, $devices, array $blank): ?array
    {
        $did = (int) ($s['device_id'] ?? 0);
        $device = $devices->get($did);
        if ($device === null) {
            return null;
        }

        return ['label' => "{$device->name} ping", 'format' => 'ms', 'unit' => 'ms', 'dashed' => false, 'group' => "ping:{$did}", 'values' => $data[$did] ?? $blank];
    }

    private function probeSeries(array $s, array $data, $probes, array $blank): ?array
    {
        $pid = (int) ($s['probe_id'] ?? 0);
        $probe = $probes->get($pid);
        if ($probe === null) {
            return null;
        }

        return [
            'label' => trim(($probe->device?->name ? "{$probe->device->name} " : '')."{$probe->name}"),
            'format' => 'ms',
            'unit' => 'ms',
            'dashed' => false,
            'group' => "probe:{$pid}",
            'values' => $data[$pid] ?? $blank,
        ];
    }

    /** @param list<list<?float>> $seriesValues */
    private function sum(array $seriesValues, int $bucketCount): array
    {
        $out = [];
        for ($i = 0; $i < $bucketCount; $i++) {
            $sum = null;
            foreach ($seriesValues as $values) {
                $v = $values[$i] ?? null;
                if ($v !== null) {
                    $sum = ($sum ?? 0) + $v;
                }
            }
            $out[] = $sum;
        }

        return $out;
    }

    private static function num(mixed $value, int $precision): ?float
    {
        return $value === null ? null : round((float) $value, $precision);
    }
}
