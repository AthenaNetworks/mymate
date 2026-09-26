<?php

namespace App\Actions\Agent;

use App\Actions\Devices\CaptureDeviceFacts;
use App\Actions\Outages\RecordOutage;
use App\Actions\Polling\PollProbes;
use App\Actions\Polling\RecordDeviceResources;
use App\Actions\Polling\RecordOpticalPower;
use App\Enums\DeviceStatus;
use App\Events\DeviceLatencyUpdated;
use App\Events\DeviceMetricsUpdated;
use App\Events\DeviceStatusChanged;
use App\Events\InterfaceUtilUpdated;
use App\Models\Agent;
use App\Models\Device;
use App\Models\NetworkInterface;
use App\Models\Probe;
use App\Services\Polling\DeviceMetrics;
use App\Services\Polling\LiveDeviceFrame;
use App\Services\Polling\LiveInterfaceFrame;
use App\Services\Polling\OpticalReading;
use App\Services\Polling\PortStats;
use App\Services\Polling\RateCalculator;
use App\Services\Polling\StorageReading;
use App\Services\Probes\ProbeResult;
use App\Support\EngineLog;
use App\Support\LiveBroadcast;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Fold a remote agent's poll results into the same pipeline central polling uses:
 * up/down status (-> DeviceStatusChanged + outage record) and interface throughput
 * (bps/util + recent-history sample + the coalesced InterfaceUtilUpdated broadcast the
 * live map colour ramp reads).
 *
 * Security: an agent may only report for devices/interfaces assigned to IT. Anything in
 * the payload that isn't the agent's is ignored - a compromised or buggy agent can't move
 * another site's data.
 *
 * The agent computes bps itself (it holds consecutive counters across its own polls, with
 * the counter-reset guard); this action computes util against the interface's known speed
 * (RateCalculator::utilPercent) so the util/colour authority stays central.
 *
 * @phpstan-type PingResult array{device_id:int, up:bool, rtt_ms?:float|null, loss_pct?:float|null, jitter_ms?:float|null}
 * @phpstan-type FlowResult array{interface_id:int, in_bps:float, out_bps:float, oper_up?:bool|null, pkts_in?:float|null, errors_in?:float|null}
 */
class IngestAgentResults
{
    public function __construct(
        private RecordOutage $outages,
        private RateCalculator $rates,
        private CaptureDeviceFacts $facts,
        private PollProbes $probes,
        private RecordOpticalPower $recordOptical,
        private RecordDeviceResources $recordResources,
    ) {}

    /** @param array<string,mixed> $payload */
    public function __invoke(Agent $agent, array $payload): void
    {
        $this->ingestPings($agent, $payload['pings'] ?? []);
        $this->ingestThroughput($agent, $payload['throughput'] ?? []);
        $this->ingestMetrics($agent, $payload['metrics'] ?? []);
        $this->ingestDiscovery($agent, $payload['discovery'] ?? []);
        $this->ingestProbes($agent, $payload['probes'] ?? []);
        // Agents older than the optical support (#11) never send this key - nothing to do.
        $this->ingestOptical($agent, $payload['optical'] ?? []);
    }

    /**
     * Fold the agent's SFP optical power reads into the interface rows through the same matcher
     * the central metrics tick uses (RecordOpticalPower - by port name, then ifIndex). Each entry
     * is one device the agent read successfully, so a device reported with no ports has had its
     * modules removed and gets cleared. Only this agent's devices are touched.
     *
     * @param  array<int,array<string,mixed>>  $optical
     */
    private function ingestOptical(Agent $agent, array $optical): void
    {
        if ($optical === []) {
            return;
        }
        $wanted = collect($optical)->pluck('device_id')->all();
        $owned = Device::where('agent_id', $agent->id)->whereIn('id', $wanted)->pluck('id')->flip();

        foreach ($optical as $d) {
            $deviceId = (int) ($d['device_id'] ?? 0);
            if (! isset($owned[$deviceId])) {
                continue; // not this agent's device - ignore
            }
            $readings = [];
            foreach ((array) ($d['ports'] ?? []) as $p) {
                if (! is_array($p)) {
                    continue;
                }
                $name = trim((string) ($p['name'] ?? ''));
                $readings[] = new OpticalReading(
                    ifIndex: isset($p['if_index']) && (int) $p['if_index'] > 0 ? (int) $p['if_index'] : null,
                    name: $name !== '' ? $name : null,
                    rxDbm: OpticalReading::dbm($p['rx_dbm'] ?? null),
                    txDbm: OpticalReading::dbm($p['tx_dbm'] ?? null),
                );
            }
            ($this->recordOptical)($deviceId, $readings);
        }
    }

    /**
     * Fold the agent's service-probe verdicts into the probe rows through the same flap-dampening
     * and history the central probe loop uses (PollProbes::applyResult) - the agent ran the check
     * from its own network, the server owns the status decision. Only this agent's probes (#33).
     *
     * @param  array<int,array<string,mixed>>  $probes
     */
    private function ingestProbes(Agent $agent, array $probes): void
    {
        if ($probes === []) {
            return;
        }
        $wanted = collect($probes)->pluck('probe_id')->all();
        $models = Probe::whereIn('id', $wanted)
            ->whereIn('device_id', Device::where('agent_id', $agent->id)->select('id'))
            ->get()->keyBy('id');

        $now = now();
        $samples = [];
        foreach ($probes as $p) {
            $probe = $models->get($p['probe_id'] ?? 0);
            if ($probe === null) {
                continue; // not this agent's probe - ignore
            }
            $cert = isset($p['cert_expires']) ? CarbonImmutable::createFromTimestamp((int) $p['cert_expires']) : null;
            $result = new ProbeResult(
                (bool) ($p['up'] ?? false),
                self::num($p['latency_ms'] ?? null),
                ((string) ($p['message'] ?? '')) ?: null,
                $cert,
            );
            $samples[] = $this->probes->applyResult($probe, $result, $now);
        }

        if ($samples !== []) {
            DB::table('probe_samples')->insert($samples);
        }
    }

    /**
     * Fold an agent's discovery pass into the same tables the central discovery writes: upsert the
     * device's interfaces (keyed on device_id+if_index, so re-running refreshes rather than
     * duplicates) and apply the parsed facts. Only this agent's devices are touched (#33).
     *
     * @param  array<int,array<string,mixed>>  $discovery
     */
    private function ingestDiscovery(Agent $agent, array $discovery): void
    {
        if ($discovery === []) {
            return;
        }
        $wanted = collect($discovery)->pluck('device_id')->all();
        $devices = Device::where('agent_id', $agent->id)->whereIn('id', $wanted)->get()->keyBy('id');

        foreach ($discovery as $d) {
            $device = $devices->get($d['device_id'] ?? 0);
            if ($device === null) {
                continue; // not this agent's device - ignore
            }

            $this->applyDiscoveredInterfaces($device, $d['interfaces'] ?? []);

            if (! empty($d['routeros_facts']) && is_array($d['routeros_facts'])) {
                $this->applyRouterOsFacts($device, $d['routeros_facts']);
            } elseif (! empty($d['facts']) && is_array($d['facts'])) {
                $this->applyDiscoveredFacts($device, $d['facts']);
            }
        }
    }

    /** @param array<int,array<string,mixed>> $interfaces */
    private function applyDiscoveredInterfaces(Device $device, array $interfaces): void
    {
        foreach ($interfaces as $row) {
            if (! isset($row['if_index'])) {
                continue;
            }
            $iface = NetworkInterface::firstOrNew(['device_id' => $device->id, 'if_index' => (int) $row['if_index']]);
            $iface->name = (string) ($row['name'] ?? $iface->name);
            $iface->description = ($row['descr'] ?? null) ?: null;
            // Only overwrite the discovered speed when the agent actually read one (>0); an absent
            // ifHighSpeed must not wipe a known capacity. Overrides live on the link, not here.
            if (isset($row['speed_mbps']) && (int) $row['speed_mbps'] > 0) {
                $iface->speed_mbps = (int) $row['speed_mbps'];
            }
            if (array_key_exists('oper_up', $row) && $row['oper_up'] !== null) {
                $iface->oper_status = $row['oper_up'] ? 'up' : 'down';
            }
            $iface->save();
        }

        $device->forceFill([
            'discovered_at' => now(),
            'discovery_error' => $interfaces === []
                ? 'No interfaces returned - check the SNMP community and that the agent can reach the device.'
                : null,
        ])->save();
    }

    /** @param array<string,mixed> $facts */
    private function applyRouterOsFacts(Device $device, array $facts): void
    {
        $parsed = $this->facts->factsFromRouterOsRaw(
            (string) ($facts['version'] ?? ''),
            $facts['model'] ?? null,
            $facts['board_name'] ?? null,
            $facts['res_board_name'] ?? null,
            (string) ($facts['serial'] ?? ''),
            (string) ($facts['architecture'] ?? ''),
            (string) ($facts['cpu'] ?? ''),
            (int) ($facts['cpu_count'] ?? 0),
            (int) ($facts['cpu_frequency'] ?? 0),
            (int) ($facts['total_memory'] ?? 0),
            (string) ($facts['uptime'] ?? ''),
            (string) ($facts['location'] ?? ''),
        );

        $this->facts->applyFacts($device, $parsed);
    }

    /** @param array<string,mixed> $facts */
    private function applyDiscoveredFacts(Device $device, array $facts): void
    {
        $parsed = $this->facts->factsFromRaw(
            (string) ($facts['sys_descr'] ?? ''),
            isset($facts['uptime_ticks']) ? (int) $facts['uptime_ticks'] : null,
            isset($facts['mem_kb']) ? (int) $facts['mem_kb'] : null,
            (string) ($facts['sys_location'] ?? ''),
            array_values((array) ($facts['ent_models'] ?? [])),
            array_values((array) ($facts['ent_serials'] ?? [])),
        );

        $this->facts->applyFacts($device, $parsed);
    }

    /**
     * Fold cpu/mem/temp the agent read into the same place central metrics polling writes them:
     * the device row (map tile fast path), a history sample, and the coalesced
     * DeviceMetricsUpdated broadcast. Only this agent's devices are touched.
     *
     * @param  array<int,array<string,mixed>>  $metrics  device_id, cpu_pct, mem_used_pct, temp_c, the RF keys (see wireless()) and the extras (see resourceMetrics())
     */
    private function ingestMetrics(Agent $agent, array $metrics): void
    {
        if ($metrics === []) {
            return;
        }
        $wanted = collect($metrics)->pluck('device_id')->all();
        $devices = Device::where('agent_id', $agent->id)->whereIn('id', $wanted)->get()->keyBy('id');

        $now = now();
        $frames = [];
        $sampleRows = [];
        $resources = [];

        foreach ($metrics as $m) {
            $device = $devices->get($m['device_id'] ?? 0);
            if ($device === null) {
                continue; // not this agent's device - ignore
            }
            $cpu = self::num($m['cpu_pct'] ?? null);
            $mem = self::num($m['mem_used_pct'] ?? null);
            $temp = self::num($m['temp_c'] ?? null);
            // Device page extras (per-CPU, storage, uptime). Older agents never send them.
            $extras = self::resourceMetrics($m);
            // Wireless RF, null when an older agent didn't send it at all (see wireless()).
            $rf = self::wireless($m);
            $rfRead = $rf !== null && array_filter($rf, static fn ($v) => $v !== null) !== [];
            if ($cpu === null && $mem === null && $temp === null && $extras->isEmpty() && ! $rfRead) {
                continue; // nothing readable - don't stamp metrics_at with an empty frame
            }

            // An agent that reads RF sends it the way central polling stores it, null included,
            // so a radio that went quiet clears. One that predates it leaves the stored values be.
            $rfAttrs = $rf ?? [
                'signal_dbm' => $device->signal_dbm, 'snr_db' => $device->snr_db,
                'ccq_pct' => $device->ccq_pct, 'wireless_clients' => $device->wireless_clients,
            ];

            $attrs = RecordDeviceResources::deviceAttributes($device, $extras, $now);
            $device->forceFill([
                'cpu_pct' => $cpu, 'mem_used_pct' => $mem, 'temp_c' => $temp, 'metrics_at' => $now,
                ...($rf ?? []),
                ...$attrs,
            ])->save();
            $resources[] = [$device, $extras];

            $frames[] = [
                'device_id' => $device->id, 'cpu_pct' => $cpu, 'mem_used_pct' => $mem, 'temp_c' => $temp,
                ...$rfAttrs,
                ...LiveDeviceFrame::resources($attrs, $extras),
            ];
            $sampleRows[] = [
                'device_id' => $device->id, 'ts' => $now,
                'cpu_pct' => $cpu, 'mem_used_pct' => $mem, 'temp_c' => $temp,
                // history only gets RF this agent actually read now, never a carried-over value
                'signal_dbm' => $rf['signal_dbm'] ?? null, 'snr_db' => $rf['snr_db'] ?? null,
                'ccq_pct' => $rf['ccq_pct'] ?? null, 'wireless_clients' => $rf['wireless_clients'] ?? null,
                'uptime_s' => $extras->uptimeSeconds,
            ];
        }

        ($this->recordResources)($resources, $now);

        if ($sampleRows !== [] && config('mymate.history.enabled', true)) {
            try {
                DB::table('device_metric_samples')->insert($sampleRows);
            } catch (\Throwable $e) {
                EngineLog::warning('agent: metrics history insert failed', ['error' => $e->getMessage()]);
            }
        }

        if ($frames !== [] && config('mymate.device_metrics.broadcast', true)) {
            LiveBroadcast::sendFrames(static fn (array $chunk) => new DeviceMetricsUpdated($chunk), $frames);
        }
    }

    /**
     * The per-CPU / storage / uptime part of an agent metrics entry as a DeviceMetrics, so it goes
     * through the same RecordDeviceResources as the central tick.
     *
     *   uptime_s  int seconds
     *   cpus      [{index, load_pct}]
     *   storage   [{key, descr, type, units, size, used}] raw hrStorage values (type is the
     *             hrStorageType OID, or already one of our names from a RouterOS read; size/used
     *             in allocation units). Absent or null = not read, [] = read and nothing there.
     *
     * @param  array<string, mixed>  $m
     */
    private static function resourceMetrics(array $m): DeviceMetrics
    {
        $cpus = null;
        foreach ((array) ($m['cpus'] ?? []) as $c) {
            if (is_array($c) && isset($c['index']) && is_numeric($c['load_pct'] ?? null)) {
                $cpus[(int) $c['index']] = max(0.0, min(100.0, (float) $c['load_pct']));
            }
        }

        $storages = null;
        if (isset($m['storage']) && is_array($m['storage'])) {
            $cols = ['descr' => [], 'type' => [], 'units' => [], 'size' => [], 'used' => []];
            foreach ($m['storage'] as $e) {
                $key = is_array($e) ? trim((string) ($e['key'] ?? '')) : '';
                if ($key === '') {
                    continue;
                }
                foreach (array_keys($cols) as $col) {
                    if (isset($e[$col])) {
                        $cols[$col][$key] = (string) $e[$col];
                    }
                }
            }
            $storages = StorageReading::fromHrColumns($cols['descr'], $cols['type'], $cols['units'], $cols['size'], $cols['used']);
        }

        $uptime = $m['uptime_s'] ?? null;

        return new DeviceMetrics(
            uptimeSeconds: is_numeric($uptime) && $uptime >= 0 ? (int) $uptime : null,
            cpuLoads: $cpus,
            storages: $storages,
        );
    }

    /**
     * The wireless RF part of an agent metrics entry, tidied the same way the central drivers do
     * it (signal/snr to one decimal, ccq clamped to 0-100, a whole client count). Null when the
     * entry has none of the keys, that's an agent from before it read RF. A key sent as null is
     * a real "not available" and is kept as null.
     *
     * @param  array<string, mixed>  $m
     * @return array{signal_dbm: ?float, snr_db: ?float, ccq_pct: ?float, wireless_clients: ?int}|null
     */
    private static function wireless(array $m): ?array
    {
        $keys = ['signal_dbm', 'snr_db', 'ccq_pct', 'wireless_clients'];
        if (array_intersect($keys, array_keys($m)) === []) {
            return null;
        }
        $signal = self::num($m['signal_dbm'] ?? null);
        $snr = self::num($m['snr_db'] ?? null);
        $clients = self::num($m['wireless_clients'] ?? null);

        return [
            'signal_dbm' => $signal === null ? null : round($signal, 1),
            'snr_db' => $snr === null ? null : round($snr, 1),
            'ccq_pct' => DeviceMetrics::clampPct(self::num($m['ccq_pct'] ?? null)),
            'wireless_clients' => $clients === null || $clients < 0 ? null : (int) round($clients),
        ];
    }

    /** Coerce an incoming metric to a float or null (an agent sends null for an unread metric). */
    private static function num(mixed $v): ?float
    {
        return is_numeric($v) ? (float) $v : null;
    }

    /**
     * Fold the agent's per-device ping results into the same up/down + latency pipeline the central
     * sweep uses (PingFleet). The agent measures rtt/loss/jitter locally (older agents that report
     * only `up` still work - the latency half is simply skipped). Status uses the same flap
     * dampening (a device only flips down after `fail_threshold` misses), and the latency trend is
     * throttled per device to `ping.history_interval` so it lands about once a minute, not every
     * report. Only this agent's devices are touched.
     *
     * @param  array<int,array{device_id:int,up:bool,rtt_ms?:float|null,loss_pct?:float|null,jitter_ms?:float|null}>  $pings
     */
    private function ingestPings(Agent $agent, array $pings): void
    {
        if ($pings === []) {
            return;
        }
        $wanted = collect($pings)->pluck('device_id')->all();
        $devices = Device::where('agent_id', $agent->id)->whereIn('id', $wanted)->get()->keyBy('id');

        $threshold = max(1, (int) config('mymate.ping.fail_threshold', 3));
        $interval = max(5, (int) config('mymate.ping.history_interval', 60));
        $now = now();
        $cutoff = $now->copy()->subSeconds($interval);

        $rows = [];   // ping_samples trend inserts
        $frames = []; // live rtt/loss frames for the latency broadcast
        foreach ($pings as $p) {
            $device = $devices->get($p['device_id'] ?? 0);
            if ($device === null) {
                continue; // not this agent's device - ignore
            }

            // Up/down with flap dampening, mirroring PingFleet: down only after `threshold`
            // consecutive misses; one reply recovers immediately.
            $reachable = (bool) ($p['up'] ?? false);
            $streak = (int) $device->fail_streak;
            if ($reachable) {
                $newStreak = 0;
                $newStatus = DeviceStatus::Up;
            } else {
                $newStreak = min($streak + 1, $threshold);
                $newStatus = $newStreak >= $threshold ? DeviceStatus::Down : $device->status;
            }
            $statusChanged = $device->status !== $newStatus;
            if ($newStreak !== $streak || $statusChanged) {
                $device->fail_streak = $newStreak;
                if ($statusChanged) {
                    $device->status = $newStatus;
                    $device->last_change = $now;
                }
                $device->save();
                if ($statusChanged) {
                    $newStatus === DeviceStatus::Down ? $this->outages->open($device) : $this->outages->close($device);
                    LiveBroadcast::send(new DeviceStatusChanged($device));
                }
            }

            // Latency trend + live columns, throttled per device to the history cadence. An older
            // agent that reports no rtt/loss simply doesn't feed this half.
            $rtt = self::num($p['rtt_ms'] ?? null);
            $loss = self::num($p['loss_pct'] ?? null);
            if ($rtt === null && $loss === null) {
                continue;
            }
            if ($device->ping_at !== null && $device->ping_at->greaterThan($cutoff)) {
                continue;
            }
            $jitter = self::num($p['jitter_ms'] ?? null);
            $device->forceFill(['rtt_ms' => $rtt, 'loss_pct' => $loss, 'ping_at' => $now])->save();
            $rows[] = ['device_id' => $device->id, 'ts' => $now, 'rtt_ms' => $rtt, 'loss_pct' => $loss, 'jitter_ms' => $jitter];
            $frames[] = ['device_id' => $device->id, 'rtt_ms' => $rtt, 'loss_pct' => $loss];
        }

        if ($rows !== []) {
            DB::table('ping_samples')->insert($rows);
        }
        if ($frames !== [] && config('mymate.device_metrics.broadcast', true)) {
            LiveBroadcast::send(new DeviceLatencyUpdated($frames));
        }
    }

    /** @param array<int,array{interface_id:int,in_bps:float,out_bps:float}> $flows */
    private function ingestThroughput(Agent $agent, array $flows): void
    {
        if ($flows === []) {
            return;
        }
        $wanted = collect($flows)->pluck('interface_id')->all();
        // Interfaces on this agent's devices only (join enforces ownership).
        $ifaces = NetworkInterface::whereIn('interfaces.id', $wanted)
            ->whereIn('device_id', Device::where('agent_id', $agent->id)->select('id'))
            ->get()->keyBy('id');

        $now = now();
        $frames = [];      // device_id => interface frames
        $sampleRows = [];

        foreach ($flows as $f) {
            $iface = $ifaces->get($f['interface_id'] ?? 0);
            if ($iface === null) {
                continue;
            }
            $inBps = (int) round((float) ($f['in_bps'] ?? 0));
            $outBps = (int) round((float) ($f['out_bps'] ?? 0));
            // Interface speed is symmetric (asymmetry moved to the link, FR-28); the link's
            // effective speed drives the colour ramp separately. Util here is vs the port speed.
            $utilIn = $this->rates->utilPercent($inBps, $iface->speed_mbps);
            $utilOut = $this->rates->utilPercent($outBps, $iface->speed_mbps);

            // Port rates (per second) the agent worked out from its own counter deltas, only on
            // a cycle it read them, and the oper status. Older agents send neither.
            $port = [];
            foreach (PortStats::RATES as $name) {
                $port[$name] = self::num($f[$name] ?? null);
            }
            $read = array_filter($port, static fn ($v) => $v !== null);
            $operUp = isset($f['oper_up']) && is_bool($f['oper_up']) ? $f['oper_up'] : null;

            DB::table('interfaces')->where('id', $iface->id)->update([
                'bps_in' => $inBps, 'bps_out' => $outBps,
                'util_in' => $utilIn, 'util_out' => $utilOut,
                'last_ts' => $now, 'updated_at' => $now,
                ...$read,
                ...($operUp === null ? [] : ['oper_status' => $operUp ? 'up' : 'down']),
            ]);
            $sampleRows[] = [
                'interface_id' => $iface->id, 'ts' => $now,
                'bps_in' => $inBps, 'bps_out' => $outBps, 'util_in' => $utilIn, 'util_out' => $utilOut,
                ...$port,
                'oper_up' => $operUp,
            ];
            $frames[$iface->device_id][] = LiveInterfaceFrame::compact([
                'interface_id' => $iface->id, 'util_in' => $utilIn, 'util_out' => $utilOut,
                'speed_mbps' => $iface->speed_mbps, 'bps_in' => $inBps, 'bps_out' => $outBps,
                // $iface is the row from before the update above, so these are the changes
                ...LiveInterfaceFrame::extras($iface, $operUp, $read, $read !== []),
            ]);
        }

        $this->recordHistory($sampleRows);

        if ($frames !== []) {
            $devices = [];
            foreach ($frames as $deviceId => $ifaceFrames) {
                $devices[] = ['device_id' => $deviceId, 'status' => DeviceStatus::Up->value, 'interfaces' => $ifaceFrames];
            }
            // Split by bytes: an agent's report isn't narrowed to link ends like the central
            // tick, so a site's worth of ports in one message could be over Reverb's limit.
            LiveBroadcast::sendFrames(static fn (array $chunk) => new InterfaceUtilUpdated($chunk), $devices);
        }
    }

    /** @param list<array<string,mixed>> $rows */
    private function recordHistory(array $rows): void
    {
        if ($rows === []) {
            return;
        }
        try {
            DB::table('interface_samples')->insert($rows);
        } catch (\Throwable $e) {
            EngineLog::warning('agent: history insert failed', ['error' => $e->getMessage()]);
        }
    }
}
