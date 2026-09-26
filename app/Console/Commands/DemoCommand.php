<?php

namespace App\Console\Commands;

use App\Actions\Alerts\EvaluateAlerts;
use App\Actions\History\ManageHistoryPartitions;
use App\Actions\History\RollupHistory;
use App\Actions\Outages\RecordOutage;
use App\Actions\Polling\RecordDeviceResources;
use App\Actions\Polling\RecordOpticalPower;
use App\Enums\AlertCondition;
use App\Enums\DeviceStatus;
use App\Events\DeviceMetricsUpdated;
use App\Events\DeviceStatusChanged;
use App\Events\InterfaceUtilUpdated;
use App\Models\AlertPolicy;
use App\Models\Device;
use App\Models\Link;
use App\Models\Map;
use App\Models\NetworkInterface;
use App\Models\Outage;
use App\Models\User;
use App\Services\Polling\DeviceMetrics;
use App\Services\Polling\OpticalReading;
use App\Services\Polling\PortStats;
use App\Services\Polling\StorageReading;
use App\Support\LiveBroadcast;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Customer-facing demo driver. The sales site runs the REAL app UI + Reverb pipeline
 * but fed 100% synthetic data:
 *   --seed   create the read-only demo viewer + ensure the Mock Lab topology exists
 *   --run    the synthetic traffic simulator (a daemon; mirrors the poll loop's
 *            broadcasts so the live map/charts move exactly as they would with real gear)
 *   --clear  remove the demo viewer + mock topology
 *
 * Demo devices are the `monitored=false` mock devices (mgmt IPs in RFC 5737 space) - the
 * real ping/throughput loops never touch them, so only this simulator drives them.
 */
class DemoCommand extends Command
{
    protected $signature = 'mymate:demo
        {--seed : Seed the demo viewer account + Mock Lab topology}
        {--run : Run the synthetic traffic simulator (daemon)}
        {--once : With --run, do a single tick and exit (testing)}
        {--clear : Remove the demo viewer + mock topology}';

    protected $description = 'Drive the customer-facing demo (seed synthetic topology + simulate live traffic).';

    public function handle(): int
    {
        if ($this->option('clear')) {
            return $this->clear();
        }
        if ($this->option('seed')) {
            return $this->seed();
        }
        if ($this->option('run')) {
            return $this->runSimulator();
        }

        $this->error('Nothing to do - pass --seed, --run, or --clear.');

        return self::FAILURE;
    }

    // --- Seed -------------------------------------------------------------

    private function seed(): int
    {
        // Read-only viewer (non-admin -> RestrictWritesToAdmins makes the whole app
        // read-only for it). is_admin is not fillable - set explicitly.
        $email = (string) config('mymate.demo.email');
        $user = User::firstOrNew(['email' => $email]);
        $user->name = 'Demo Viewer';
        $user->password = (string) config('mymate.demo.password'); // 'hashed' cast auto-hashes
        $user->forceFill(['is_admin' => false])->save();
        $this->info("Demo viewer ready: {$email} (read-only).");

        // Ensure the Mock Lab topology exists (reuse the existing seeder).
        if (Device::where('monitored', false)->doesntExist()) {
            $this->call('mymate:mock');
        } else {
            $this->info('Mock topology already present.');
        }

        // Open the demo on the POPULATED map: make Mock Lab the default and drop the
        // empty stock "Main" map, so the switcher/canvas don't land on an empty map.
        $mock = Map::where('name', 'Mock Lab')->first();
        if ($mock !== null) {
            Map::where('id', '!=', $mock->id)->update(['is_default' => false]);
            $mock->forceFill(['is_default' => true, 'position' => 0])->save();
            Map::where('name', 'Main')->whereDoesntHave('positions')->delete();
            $this->info('Mock Lab set as the default map.');
        }

        // Alerting policies (no transports -> nothing is actually delivered; the events
        // just populate the Alerts view, which the simulator evaluates each tick):
        //  - device-down (dependency-aware - a down root suppresses its down subtree);
        //  - link capacity exceeded (fires when a link crosses the util threshold).
        AlertPolicy::firstOrCreate(
            ['name' => 'Device down'],
            ['condition' => AlertCondition::DeviceDown, 'enabled' => true, 'params' => []],
        );
        AlertPolicy::firstOrCreate(
            ['name' => 'Link capacity exceeded'],
            ['condition' => AlertCondition::HighUtil, 'enabled' => true, 'params' => ['threshold' => 70]],
        );

        // Outages: open one for each currently-down device + seed a few historical
        // (closed) ones so the Outages view isn't blank on first view.
        $rec = app(RecordOutage::class);
        foreach (Device::where('monitored', false)->where('status', DeviceStatus::Down)->get() as $d) {
            $rec->open($d);
        }
        if (Outage::whereNotNull('ended_at')->doesntExist()) {
            $deviceIds = Device::where('monitored', false)->pluck('id');
            foreach (range(1, 6) as $i) {
                $start = now()->subHours($i * 7)->subMinutes($i * 11);
                $dur = 60 * ($i * 4 + 2);
                Outage::create([
                    'device_id' => $deviceIds->random(),
                    'started_at' => $start,
                    'ended_at' => $start->copy()->addSeconds($dur),
                    'duration_s' => $dur,
                    'cause' => 'unreachable',
                ]);
            }
        }

        app(ManageHistoryPartitions::class)(); // so history samples have a partition to land in
        $this->backfillHistory();

        return self::SUCCESS;
    }

    /**
     * Seed ~24h of per-minute history for every mock device - throughput (with port packets /
     * errors / discards), cpu/mem/temp, per-CPU load, storage, uptime, RF, optical and ping - so the inspector charts are populated the moment the demo opens instead
     * of accruing from zero ("No history yet"). Uses the same synth generators as the
     * live tick (both are keyed on epoch seconds), so the simulator's live samples
     * continue the backfilled series seamlessly. Replaces the window on re-run.
     */
    private function backfillHistory(): void
    {
        $devices = Device::where('monitored', false)->with('interfaces')->get();
        if ($devices->isEmpty()) {
            return;
        }
        [$capOut, $capIn] = $this->linkCaps();

        $step = 60;
        $to = now()->startOfSecond();
        $from = $to->copy()->subDay()->addSeconds($step); // stays inside the partition window (yesterday..)

        $deviceIds = $devices->pluck('id');
        $ifaceIds = $devices->flatMap(fn (Device $d) => $d->interfaces->pluck('id'));
        DB::table('interface_samples')->whereIn('interface_id', $ifaceIds)->where('ts', '<', $to)->delete();
        DB::table('device_metric_samples')->whereIn('device_id', $deviceIds)->where('ts', '<', $to)->delete();
        DB::table('ping_samples')->whereIn('device_id', $deviceIds)->where('ts', '<', $to)->delete();
        DB::table('cpu_samples')->whereIn('device_id', $deviceIds)->where('ts', '<', $to)->delete();
        DB::table('storage_samples')->whereIn('device_id', $deviceIds)->where('ts', '<', $to)->delete();
        DB::table('optical_samples')->whereIn('interface_id', $ifaceIds)->where('ts', '<', $to)->delete();

        // The storage rows have to exist before their history can point at them: write the
        // current state once through the real recorder, then map (device, key) to the row id.
        $t0 = (float) $to->timestamp;
        app(RecordDeviceResources::class)($devices->map(fn (Device $d) => [
            $d, new DeviceMetrics(storages: $this->synthStorages($d, $this->synthMetrics($d->id, $t0)[1], $t0)),
        ])->all(), $to);
        $storageIds = [];
        foreach (DB::table('device_storages')->whereIn('device_id', $deviceIds)->get(['id', 'device_id', 'storage_key']) as $r) {
            $storageIds[$r->device_id][$r->storage_key] = $r->id;
        }

        $iface = [];
        $metric = [];
        $ping = [];
        $cpuRows = [];
        $storageRows = [];
        $opticalRows = [];
        for ($ts = $from->copy(); $ts <= $to; $ts->addSeconds($step)) {
            $t = (float) $ts->timestamp;
            $stamp = $ts->toDateTimeString();
            foreach ($devices as $device) {
                [$cpu, $mem, $temp] = $this->synthMetrics($device->id, $t);
                $metric[] = [
                    'device_id' => $device->id, 'ts' => $stamp, 'cpu_pct' => $cpu, 'mem_used_pct' => $mem, 'temp_c' => $temp,
                    ...$this->synthRf($device, $t),
                    'uptime_s' => $this->synthUptime($device->id, $t),
                ];
                [$rtt, $jitter] = $this->synthPing($device->id, $t);
                $ping[] = ['device_id' => $device->id, 'ts' => $stamp, 'rtt_ms' => $rtt, 'loss_pct' => 0.0, 'jitter_ms' => $jitter];
                foreach ($this->synthCpus($device->id, $cpu, $t) as $index => $load) {
                    $cpuRows[] = ['device_id' => $device->id, 'cpu_index' => $index, 'ts' => $stamp, 'load_pct' => $load];
                }
                foreach ($this->synthStorages($device, $mem, $t) as $st) {
                    if (isset($storageIds[$device->id][$st->key])) {
                        $storageRows[] = [
                            'storage_id' => $storageIds[$device->id][$st->key], 'device_id' => $device->id, 'ts' => $stamp,
                            'used_pct' => $st->usedPct(), 'used_bytes' => $st->usedBytes, 'size_bytes' => $st->sizeBytes,
                        ];
                    }
                }

                foreach ($device->interfaces as $if) {
                    [$utilIn, $utilOut] = $this->synthUtil($if->id, $t);
                    $speedIn = (int) ($capIn[$if->id] ?? ($if->speed_mbps ?: 1000));
                    $speedOut = (int) ($capOut[$if->id] ?? ($if->speed_up_mbps ?: $if->speed_mbps ?: 1000));
                    $bpsIn = (int) round($utilIn / 100 * $speedIn * 1_000_000);
                    $bpsOut = (int) round($utilOut / 100 * $speedOut * 1_000_000);
                    $iface[] = [
                        'interface_id' => $if->id, 'ts' => $stamp,
                        'bps_in' => $bpsIn, 'bps_out' => $bpsOut,
                        'util_in' => $utilIn, 'util_out' => $utilOut,
                        ...$this->synthPort($if->id, $bpsIn, $bpsOut, max($utilIn, $utilOut)),
                        'oper_up' => true,
                    ];
                    if (($optical = $this->synthOptical($if, $t)) !== null) {
                        $opticalRows[] = ['interface_id' => $if->id, 'ts' => $stamp, 'rx_dbm' => $optical[0], 'tx_dbm' => $optical[1]];
                    }
                }
            }
        }

        foreach (['interface_samples' => $iface, 'device_metric_samples' => $metric, 'ping_samples' => $ping,
            'cpu_samples' => $cpuRows, 'storage_samples' => $storageRows, 'optical_samples' => $opticalRows] as $table => $rows) {
            foreach (array_chunk($rows, 1000) as $chunk) {
                DB::table($table)->insert($chunk);
            }
        }

        // The window was rewritten under any rollups already made, recompute them from raw.
        RollupHistory::rewind(['interface', 'device_metric', 'ping', 'cpu', 'storage', 'optical']);

        $this->info('Backfilled 24h of demo history ('.count($iface).' throughput, '.count($metric).' metric, '.count($ping).' ping samples).');
    }

    private function clear(): int
    {
        User::where('email', config('mymate.demo.email'))->delete();
        $this->call('mymate:mock', ['--clear' => true]);
        $this->info('Demo viewer + mock topology removed.');

        return self::SUCCESS;
    }

    // --- Simulator --------------------------------------------------------

    private function runSimulator(): int
    {
        $tick = max(1, (int) config('mymate.demo.tick', 3));
        app(ManageHistoryPartitions::class)(); // create-ahead once at start

        $this->info('Demo simulator running (Ctrl-C to stop)...');
        do {
            $this->simulateTick();
            if ($this->option('once')) {
                break;
            }
            sleep($tick);
        } while (true);

        return self::SUCCESS;
    }

    /** One synthetic poll tick: move util, maybe flap a device, broadcast + record. */
    private function simulateTick(): void
    {
        $now = now();
        $t = microtime(true);

        $devices = Device::where('monitored', false)->with('interfaces')->get();
        $this->maybeFlap($devices);

        [$capOut, $capIn] = $this->linkCaps();

        $frames = [];
        $ifaceUpdates = [];
        $sampleRows = [];
        $metricFrames = [];   // cpu/mem/temp broadcast
        $metricSamples = [];  // cpu/mem/temp history
        $pingSamples = [];    // latency/loss/jitter history
        $resources = [];      // per-CPU / storage for RecordDeviceResources

        foreach ($devices as $device) {
            $down = $device->status === DeviceStatus::Down;
            $ifaceFrames = [];

            // Synthesise cpu/mem/temp for an up device (a down one reports nothing - leave its
            // last values, like a real poll). Smooth oscillation keeps the tiles + graphs alive.
            if (! $down) {
                [$cpu, $mem, $temp] = $this->synthMetrics($device->id, $t);
                [$rtt, $jitter] = $this->synthPing($device->id, $t);
                $loss = mt_rand(0, 99) < 3 ? (float) mt_rand(1, 5) : 0.0; // the odd dropped packet
                $rf = $this->synthRf($device, $t);
                // per-CPU, storage and uptime go through the real recorder, same rows a poll writes
                $extras = new DeviceMetrics(
                    uptimeSeconds: $this->synthUptime($device->id, $t),
                    cpuLoads: $this->synthCpus($device->id, $cpu, $t),
                    storages: $this->synthStorages($device, $mem, $t),
                );
                $device->forceFill([
                    'cpu_pct' => $cpu, 'mem_used_pct' => $mem, 'temp_c' => $temp, 'metrics_at' => $now,
                    'rtt_ms' => $rtt, 'loss_pct' => $loss, 'ping_at' => $now,
                    ...$rf,
                    ...RecordDeviceResources::deviceAttributes($device, $extras, $now),
                ])->save();
                $resources[] = [$device, $extras];
                $pingSamples[] = ['device_id' => $device->id, 'ts' => $now, 'rtt_ms' => $rtt, 'loss_pct' => $loss, 'jitter_ms' => $jitter];
                $metricFrames[] = [
                    'device_id' => $device->id, 'cpu_pct' => $cpu, 'mem_used_pct' => $mem, 'temp_c' => $temp, ...$rf,
                ];
                $metricSamples[] = [
                    'device_id' => $device->id, 'ts' => $now, 'cpu_pct' => $cpu, 'mem_used_pct' => $mem, 'temp_c' => $temp,
                    ...$rf, 'uptime_s' => $extras->uptimeSeconds,
                ];

                // SFP light levels, through the same recorder as the real optical read
                $optical = [];
                foreach ($device->interfaces as $if) {
                    if (($o = $this->synthOptical($if, $t)) !== null) {
                        $optical[] = new OpticalReading(ifIndex: null, name: $if->name, rxDbm: $o[0], txDbm: $o[1]);
                    }
                }
                if ($optical !== []) {
                    app(RecordOpticalPower::class)($device->id, $optical);
                }
            } else {
                // Down device: pings time out - 100% loss, no RTT (mirrors the real ping loop).
                $device->forceFill(['rtt_ms' => null, 'loss_pct' => 100.0, 'ping_at' => $now])->save();
                $pingSamples[] = ['device_id' => $device->id, 'ts' => $now, 'rtt_ms' => null, 'loss_pct' => 100.0, 'jitter_ms' => null];
            }

            foreach ($device->interfaces as $if) {
                if ($down) {
                    // Down device: no traffic. Clear the live columns (so its links grey
                    // out and don't linger at a stale util or trip a capacity alert), and
                    // broadcast nulls.
                    $ifaceUpdates[] = ['id' => $if->id, 'util_in' => null, 'util_out' => null, 'bps_in' => null, 'bps_out' => null, ...PortStats::none()];
                    $ifaceFrames[] = $this->frame($if->id, null, null, $if->speed_mbps, null, null, 'down');

                    continue;
                }

                [$utilIn, $utilOut] = $this->synthUtil($if->id, $t);
                $speedIn = (int) ($capIn[$if->id] ?? ($if->speed_mbps ?: 1000));
                // Size outbound bps against the link's effective capacity so link util
                // (bps_out / effective speed) lands at the synthetic util%, never >100%.
                $speedOut = (int) ($capOut[$if->id] ?? ($if->speed_up_mbps ?: $if->speed_mbps ?: 1000));
                $bpsIn = (int) round($utilIn / 100 * $speedIn * 1_000_000);
                $bpsOut = (int) round($utilOut / 100 * $speedOut * 1_000_000);

                $port = $this->synthPort($if->id, $bpsIn, $bpsOut, max($utilIn, $utilOut));
                $ifaceUpdates[] = [
                    'id' => $if->id,
                    'util_in' => $utilIn, 'util_out' => $utilOut,
                    'bps_in' => $bpsIn, 'bps_out' => $bpsOut,
                    ...$port,
                ];
                $sampleRows[] = [
                    'interface_id' => $if->id, 'ts' => $now,
                    'bps_in' => $bpsIn, 'bps_out' => $bpsOut,
                    'util_in' => $utilIn, 'util_out' => $utilOut,
                    ...$port, 'oper_up' => true,
                ];
                $ifaceFrames[] = $this->frame($if->id, $utilIn, $utilOut, $if->speed_mbps, $bpsIn, $bpsOut, 'up');
            }

            $frames[] = [
                'device_id' => $device->id,
                'status' => $device->status->value,
                'interfaces' => $ifaceFrames,
            ];
        }

        $this->persistInterfaces($ifaceUpdates);
        $this->recordHistory($sampleRows);
        $this->recordMetricHistory($metricSamples);
        $this->insertSamples('ping_samples', $pingSamples);
        app(RecordDeviceResources::class)($resources, $now);

        if ($frames !== []) {
            LiveBroadcast::send(new InterfaceUtilUpdated($frames));
        }
        if ($metricFrames !== []) {
            LiveBroadcast::send(new DeviceMetricsUpdated($metricFrames));
        }

        // Raise/resolve alerts for the current down devices (populates the Alerts view).
        app(EvaluateAlerts::class)();
    }

    /**
     * A link-bound interface's OUTBOUND bps must be measured against the LINK's
     * effective speed (slower end / override) - that's what Link::util() divides by.
     * Computing it against the interface's own (faster) speed makes link util blow
     * past 100%. Maps each link end's interface id -> the capacity (Mbps) to size
     * its bps_out against, and its bps_in too: what arrives at A is what B sent, so on an
     * asymmetric radio link (500 down / 50 up) A's inbound is capped by the B->A speed.
     *
     * @return array{0: array<int, int|null>, 1: array<int, int|null>} [capOut, capIn]
     */
    private function linkCaps(): array
    {
        $capOut = $capIn = [];
        foreach (Link::with(['aInterface:id,speed_mbps', 'bInterface:id,speed_mbps'])->get() as $l) {
            $capOut[$l->a_interface_id] = $l->effAbMbps();
            $capOut[$l->b_interface_id] = $l->effBaMbps();
            $capIn[$l->a_interface_id] = $l->effBaMbps();
            $capIn[$l->b_interface_id] = $l->effAbMbps();
        }

        return [$capOut, $capIn];
    }

    /**
     * Smooth per-device latency + jitter (ms), seeded off the device id like the other
     * synth generators - each device gets its own baseline and swing.
     *
     * @return array{0: float, 1: float}
     */
    private function synthPing(int $devId, float $t): array
    {
        $base = 2 + (($devId * 2654435761) % 23);                 // 2-24ms baseline
        $amp = 1 + (($devId * 40503) % 6);                        // 1-6ms swing
        $period = 30 + (($devId * 2246822519) % 60);              // 30-90s
        $phase = (($devId * 3266489917) % 628) / 100.0;           // 0-6.28
        $rtt = max(0.5, $base + $amp * sin($t / $period + $phase) + mt_rand(-80, 80) / 100);
        $jitter = max(0.1, $amp / 3 + mt_rand(-30, 30) / 100);

        return [round($rtt, 1), round($jitter, 1)];
    }

    /** Smooth per-interface oscillation (sine + jitter) so live graphs look organic. */
    private function synthUtil(int $ifId, float $t): array
    {
        $mk = function (int $salt) use ($ifId, $t): float {
            $base = 15 + (($ifId * 2654435761 + $salt) & 0x3F);       // ~15-78%
            $amp = 8 + ((($ifId + $salt) * 40503) % 22);              // 8-30
            $period = 24 + ((($ifId + $salt) * 2246822519) % 50);     // 24-74s
            $phase = ((($ifId + $salt) * 3266489917) % 628) / 100.0;  // 0-6.28
            $v = $base + $amp * sin($t / $period + $phase) + mt_rand(-250, 250) / 100;

            return round(max(0.0, min(100.0, $v)), 2);
        };

        return [$mk(1), $mk(7)];
    }

    /**
     * Synthesise cpu% / mem% / temp(C) for a device - smooth per-device oscillation (each metric
     * has its own baseline, amplitude, period and phase seeded off the device id) so the tiles and
     * history graphs look organic and each device differs.
     *
     * @return array{0: float, 1: float, 2: float}
     */
    private function synthMetrics(int $devId, float $t): array
    {
        $wave = function (int $salt, float $lo, float $hi) use ($devId, $t): float {
            $mid = ($lo + $hi) / 2;
            $amp = ($hi - $lo) / 2;
            $period = 40 + ((($devId + $salt) * 2246822519) % 80);    // 40-120s
            $phase = ((($devId + $salt) * 3266489917) % 628) / 100.0;  // 0-6.28
            $v = $mid + $amp * sin($t / $period + $phase) + mt_rand(-150, 150) / 100;

            return round(max($lo, min($hi, $v)), 1);
        };

        // cpu quieter with occasional spikes, mem steadier and higher, temp warm.
        return [$wave(1, 4, 55), $wave(9, 35, 82), $wave(17, 34, 62)];
    }

    /**
     * Port packets / errors / discards per second to go with a synthetic bps. Packet size is a
     * steady per-port mix, errors are the odd blip, and discards only show up once the port runs
     * hot, which is what they look like on a real congested link.
     *
     * @return array<string, float>
     */
    private function synthPort(int $ifId, int $bpsIn, int $bpsOut, float $util): array
    {
        $avgBytes = 400 + (($ifId * 2654435761) % 700); // 400-1100 byte average packet
        $pktsIn = round($bpsIn / 8 / $avgBytes, 1);
        $pktsOut = round($bpsOut / 8 / $avgBytes, 1);
        $blip = static fn (): float => mt_rand(0, 99) < 4 ? mt_rand(1, 30) / 10 : 0.0;
        $hot = max(0.0, $util - 75) / 25; // 0 below 75% util, up to 1 at 100%

        return [
            'pkts_in' => $pktsIn,
            'pkts_out' => $pktsOut,
            'errors_in' => $ifId % 4 === 0 ? $blip() : 0.0, // a couple of ports with a dodgy cable
            'errors_out' => 0.0,
            'discards_in' => round($pktsIn * 0.002 * $hot, 2),
            'discards_out' => round($pktsOut * 0.004 * $hot + $blip() / 10, 2),
        ];
    }

    /**
     * Uptime that climbs and now and then resets, so the device page has a reboot to show. Each
     * device reboots on its own 1 to 22 week cycle, and CPE-RAD every couple of days.
     */
    private function synthUptime(int $devId, float $t): int
    {
        $period = (7 + (($devId * 37) % 150)) * 86400;
        $offset = ($devId * 2654435761) % $period;

        return 600 + (int) fmod($t + $offset, $period);
    }

    /**
     * Load per core around the overall cpu figure, 1 to 4 cores depending on the device.
     *
     * @return array<int, float>
     */
    private function synthCpus(int $devId, float $cpu, float $t): array
    {
        $cores = [1, 2, 4, 4][$devId % 4];
        $out = [];
        for ($i = 0; $i < $cores; $i++) {
            $swing = 8 * sin($t / (30 + $i * 7) + $devId + $i);
            $out[$i] = round(max(0.0, min(100.0, $cpu + $swing + ($i === 0 ? 6 : 0))), 1);
        }

        return $out;
    }

    /**
     * RAM plus a system disk (a flash chip on the MikroTiks, a real disk on the server), RAM used
     * following the synthetic memory %, disk use creeping over the day.
     *
     * @return list<StorageReading>
     */
    private function synthStorages(Device $device, float $memPct, float $t): array
    {
        $server = $device->device_type?->value === 'server';
        $ram = ($server ? 65536 : [256, 512, 1024, 2048][$device->id % 4]) * 1048576;
        $disk = $server ? 960 * 1073741824 : 128 * 1048576;
        $diskPct = 20 + ($device->id * 13) % 50 + 2 * sin($t / 43200);

        $out = [
            new StorageReading('memory', $server ? 'Physical memory' : 'main memory', 'ram', $ram, (int) ($ram * $memPct / 100)),
            new StorageReading('system-disk', $server ? '/' : 'system disk', $server ? 'fixed_disk' : 'flash', $disk, (int) ($disk * $diskPct / 100)),
        ];
        if ($server) {
            $out[] = new StorageReading('swap', 'Swap space', 'virtual_memory', 8 * 1073741824, (int) (8 * 1073741824 * 0.03));
        }

        return $out;
    }

    /**
     * SFP light for the fibre ports (named sfp*), a steady level per port with a slow wobble.
     *
     * @return array{0: float, 1: float}|null [rx dBm, tx dBm]
     */
    private function synthOptical(NetworkInterface $if, float $t): ?array
    {
        if (! str_starts_with(strtolower((string) $if->name), 'sfp')) {
            return null;
        }
        $rx = -3.0 - ($if->id % 7) - 0.4 * sin($t / 900 + $if->id);
        $tx = -1.5 - ($if->id % 3) * 0.5 + 0.1 * sin($t / 1800);

        return [round($rx, 2), round($tx, 2)];
    }

    /**
     * Wireless RF for the radios in the lab: the AP sees a handful of clients, the CPE is a
     * station with one link back to its AP. Everything else is wired, so all null.
     *
     * @return array{signal_dbm: ?float, snr_db: ?float, ccq_pct: ?float, wireless_clients: ?int}
     */
    private function synthRf(Device $device, float $t): array
    {
        $ap = $device->device_type?->value === 'ap';
        $station = str_contains(strtoupper($device->name), 'CPE');
        if (! $ap && ! $station) {
            return ['signal_dbm' => null, 'snr_db' => null, 'ccq_pct' => null, 'wireless_clients' => null];
        }
        $wobble = sin($t / 300 + $device->id);

        return [
            'signal_dbm' => round(($ap ? -61 : -66) + 3 * $wobble, 1),
            'snr_db' => round(($ap ? 34 : 29) + 3 * $wobble, 1),
            'ccq_pct' => round(min(100, ($ap ? 92 : 86) + 5 * $wobble), 1),
            'wireless_clients' => $ap ? 8 + (int) round(6 + 6 * sin($t / 1800)) : null,
        ];
    }

    /** @param list<array<string,mixed>> $rows */
    private function recordMetricHistory(array $rows): void
    {
        $this->insertSamples('device_metric_samples', $rows);
    }

    /** Small per-tick chance to flap one device up<->down for liveliness. */
    private function maybeFlap($devices): void
    {
        if ($devices->isEmpty() || mt_rand(0, 10000) / 10000 > (float) config('mymate.demo.flip_chance', 0.015)) {
            return;
        }
        $device = $devices->random();
        $newStatus = $device->status === DeviceStatus::Down ? DeviceStatus::Up : DeviceStatus::Down;
        $device->forceFill(['status' => $newStatus, 'last_change' => now()])->save();
        LiveBroadcast::send(new DeviceStatusChanged($device));

        // Mirror the real up/down path - open an outage on down, close it on recovery.
        $rec = app(RecordOutage::class);
        $newStatus === DeviceStatus::Down ? $rec->open($device) : $rec->close($device);
    }

    /** @return array<string,mixed> */
    private function frame(int $id, ?float $ui, ?float $uo, ?int $speed, ?int $bi, ?int $bo, string $status): array
    {
        return [
            'interface_id' => $id, 'util_in' => $ui, 'util_out' => $uo,
            'speed_mbps' => $speed, 'bps_in' => $bi, 'bps_out' => $bo, 'status' => $status,
        ];
    }

    /** @param list<array<string,mixed>> $rows */
    private function persistInterfaces(array $rows): void
    {
        foreach (array_chunk($rows, 200) as $chunk) {
            foreach ($chunk as $r) {
                DB::table('interfaces')->where('id', $r['id'])->update([
                    'util_in' => $r['util_in'], 'util_out' => $r['util_out'],
                    'bps_in' => $r['bps_in'], 'bps_out' => $r['bps_out'],
                    ...array_intersect_key($r, array_flip(PortStats::RATES)),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /** @param list<array<string,mixed>> $rows */
    private function recordHistory(array $rows): void
    {
        $this->insertSamples('interface_samples', $rows);
    }

    /**
     * Insert history rows; on failure (usually a missing day partition - the daemon
     * outlives the start-of-run create-ahead window) roll the partitions forward and
     * retry once. Still best-effort overall: history must never break a tick.
     *
     * @param  list<array<string,mixed>>  $rows
     */
    private function insertSamples(string $table, array $rows): void
    {
        if ($rows === []) {
            return;
        }
        try {
            DB::table($table)->insert($rows);
        } catch (\Throwable) {
            try {
                app(ManageHistoryPartitions::class)();
                DB::table($table)->insert($rows);
            } catch (\Throwable) {
                // best-effort
            }
        }
    }
}
