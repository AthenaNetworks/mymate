<?php

namespace App\Console\Commands;

use App\Actions\Alerts\EvaluateAlerts;
use App\Actions\History\ManageHistoryPartitions;
use App\Actions\Outages\RecordOutage;
use App\Enums\AlertCondition;
use App\Enums\DeviceStatus;
use App\Events\DeviceMetricsUpdated;
use App\Events\DeviceStatusChanged;
use App\Events\InterfaceUtilUpdated;
use App\Models\AlertPolicy;
use App\Models\Device;
use App\Models\Link;
use App\Models\Map;
use App\Models\Outage;
use App\Models\User;
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
     * Seed ~24h of per-minute history for every mock device - throughput, cpu/mem/temp
     * and ping - so the inspector charts are populated the moment the demo opens instead
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
        $capOut = $this->linkCapOut();

        $step = 60;
        $to = now()->startOfSecond();
        $from = $to->copy()->subDay()->addSeconds($step); // stays inside the partition window (yesterday..)

        $deviceIds = $devices->pluck('id');
        $ifaceIds = $devices->flatMap(fn (Device $d) => $d->interfaces->pluck('id'));
        DB::table('interface_samples')->whereIn('interface_id', $ifaceIds)->where('ts', '<', $to)->delete();
        DB::table('device_metric_samples')->whereIn('device_id', $deviceIds)->where('ts', '<', $to)->delete();
        DB::table('ping_samples')->whereIn('device_id', $deviceIds)->where('ts', '<', $to)->delete();

        $iface = [];
        $metric = [];
        $ping = [];
        for ($ts = $from->copy(); $ts <= $to; $ts->addSeconds($step)) {
            $t = (float) $ts->timestamp;
            $stamp = $ts->toDateTimeString();
            foreach ($devices as $device) {
                [$cpu, $mem, $temp] = $this->synthMetrics($device->id, $t);
                $metric[] = [
                    'device_id' => $device->id, 'ts' => $stamp, 'cpu_pct' => $cpu, 'mem_used_pct' => $mem, 'temp_c' => $temp,
                    'signal_dbm' => null, 'snr_db' => null, 'ccq_pct' => null, 'wireless_clients' => null,
                ];
                [$rtt, $jitter] = $this->synthPing($device->id, $t);
                $ping[] = ['device_id' => $device->id, 'ts' => $stamp, 'rtt_ms' => $rtt, 'loss_pct' => 0.0, 'jitter_ms' => $jitter];

                foreach ($device->interfaces as $if) {
                    [$utilIn, $utilOut] = $this->synthUtil($if->id, $t);
                    $speedIn = (int) ($if->speed_mbps ?: 1000);
                    $speedOut = (int) ($capOut[$if->id] ?? ($if->speed_up_mbps ?: $if->speed_mbps ?: 1000));
                    $iface[] = [
                        'interface_id' => $if->id, 'ts' => $stamp,
                        'bps_in' => (int) round($utilIn / 100 * $speedIn * 1_000_000),
                        'bps_out' => (int) round($utilOut / 100 * $speedOut * 1_000_000),
                        'util_in' => $utilIn, 'util_out' => $utilOut,
                    ];
                }
            }
        }

        foreach (array_chunk($iface, 1000) as $chunk) {
            DB::table('interface_samples')->insert($chunk);
        }
        foreach (array_chunk($metric, 1000) as $chunk) {
            DB::table('device_metric_samples')->insert($chunk);
        }
        foreach (array_chunk($ping, 1000) as $chunk) {
            DB::table('ping_samples')->insert($chunk);
        }

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

        $capOut = $this->linkCapOut();

        $frames = [];
        $ifaceUpdates = [];
        $sampleRows = [];
        $metricFrames = [];   // cpu/mem/temp broadcast
        $metricSamples = [];  // cpu/mem/temp history
        $pingSamples = [];    // latency/loss/jitter history

        foreach ($devices as $device) {
            $down = $device->status === DeviceStatus::Down;
            $ifaceFrames = [];

            // Synthesise cpu/mem/temp for an up device (a down one reports nothing - leave its
            // last values, like a real poll). Smooth oscillation keeps the tiles + graphs alive.
            if (! $down) {
                [$cpu, $mem, $temp] = $this->synthMetrics($device->id, $t);
                [$rtt, $jitter] = $this->synthPing($device->id, $t);
                $loss = mt_rand(0, 99) < 3 ? (float) mt_rand(1, 5) : 0.0; // the odd dropped packet
                $device->forceFill([
                    'cpu_pct' => $cpu, 'mem_used_pct' => $mem, 'temp_c' => $temp, 'metrics_at' => $now,
                    'rtt_ms' => $rtt, 'loss_pct' => $loss, 'ping_at' => $now,
                ])->save();
                $pingSamples[] = ['device_id' => $device->id, 'ts' => $now, 'rtt_ms' => $rtt, 'loss_pct' => $loss, 'jitter_ms' => $jitter];
                $metricFrames[] = [
                    'device_id' => $device->id, 'cpu_pct' => $cpu, 'mem_used_pct' => $mem, 'temp_c' => $temp,
                    'signal_dbm' => null, 'snr_db' => null, 'ccq_pct' => null, 'wireless_clients' => null,
                ];
                $metricSamples[] = [
                    'device_id' => $device->id, 'ts' => $now, 'cpu_pct' => $cpu, 'mem_used_pct' => $mem, 'temp_c' => $temp,
                    'signal_dbm' => null, 'snr_db' => null, 'ccq_pct' => null, 'wireless_clients' => null,
                ];
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
                    $ifaceUpdates[] = ['id' => $if->id, 'util_in' => null, 'util_out' => null, 'bps_in' => null, 'bps_out' => null];
                    $ifaceFrames[] = $this->frame($if->id, null, null, $if->speed_mbps, null, null, 'down');

                    continue;
                }

                [$utilIn, $utilOut] = $this->synthUtil($if->id, $t);
                $speedIn = (int) ($if->speed_mbps ?: 1000);
                // Size outbound bps against the link's effective capacity so link util
                // (bps_out / effective speed) lands at the synthetic util%, never >100%.
                $speedOut = (int) ($capOut[$if->id] ?? ($if->speed_up_mbps ?: $if->speed_mbps ?: 1000));
                $bpsIn = (int) round($utilIn / 100 * $speedIn * 1_000_000);
                $bpsOut = (int) round($utilOut / 100 * $speedOut * 1_000_000);

                $ifaceUpdates[] = [
                    'id' => $if->id,
                    'util_in' => $utilIn, 'util_out' => $utilOut,
                    'bps_in' => $bpsIn, 'bps_out' => $bpsOut,
                ];
                $sampleRows[] = [
                    'interface_id' => $if->id, 'ts' => $now,
                    'bps_in' => $bpsIn, 'bps_out' => $bpsOut,
                    'util_in' => $utilIn, 'util_out' => $utilOut,
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

        if ($frames !== []) {
            InterfaceUtilUpdated::dispatch($frames);
        }
        if ($metricFrames !== []) {
            DeviceMetricsUpdated::dispatch($metricFrames);
        }

        // Raise/resolve alerts for the current down devices (populates the Alerts view).
        app(EvaluateAlerts::class)();
    }

    /**
     * A link-bound interface's OUTBOUND bps must be measured against the LINK's
     * effective speed (slower end / override) - that's what Link::util() divides by.
     * Computing it against the interface's own (faster) speed makes link util blow
     * past 100%. Maps each link end's interface id -> the capacity (Mbps) to size
     * its bps_out against.
     *
     * @return array<int, int|null>
     */
    private function linkCapOut(): array
    {
        $capOut = [];
        foreach (Link::with(['aInterface:id,speed_mbps', 'bInterface:id,speed_mbps'])->get() as $l) {
            $capOut[$l->a_interface_id] = $l->effAbMbps();
            $capOut[$l->b_interface_id] = $l->effBaMbps();
        }

        return $capOut;
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
        DeviceStatusChanged::dispatch($device);

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
                    'bps_in' => $r['bps_in'], 'bps_out' => $r['bps_out'], 'updated_at' => now(),
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
     * @param list<array<string,mixed>> $rows
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
