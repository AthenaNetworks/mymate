<?php

namespace App\Console\Commands;

use App\Actions\History\ManageHistoryPartitions;
use App\Enums\PollMethod;
use App\Jobs\DiscoverInterfacesJob;
use App\Jobs\EvaluateAlertsJob;
use App\Jobs\ManageHistoryPartitionsJob;
use App\Jobs\ScanSubnetJob;
use App\Models\Device;
use App\Models\Subnet;
use App\Services\Polling\PingDispatcher;
use App\Services\Polling\PollDispatcher;
use App\Support\EngineLog;
use App\Support\Settings;
use Illuminate\Console\Command;

/**
 * The poll-loop daemon. Drives two independent cadences (the Laravel scheduler's
 * 60s floor is too coarse): the fping up/down sweep (~5s) and the per-device
 * throughput poll (~12s), plus a slow interface-discovery sweep. Run under
 * Supervisor in prod.
 */
class LoopCommand extends Command
{
    protected $signature = 'mymate:loop
        {--once : Dispatch a single ping sweep + one throughput round and exit (cron/testing)}
        {--discover : Dispatch interface discovery for pollable devices and exit}
        {--scan : Dispatch a discovery scan for every due, enabled subnet and exit}
        {--partitions : Maintain history partitions (create upcoming, drop expired) and exit}';

    protected $description = 'Dispatch the polling loops (fping up/down ~5s, throughput ~12s, subnet discovery, history).';

    public function handle(): int
    {
        if ($this->option('discover')) {
            $n = $this->dispatchDiscovery();
            $this->info("Dispatched discovery for {$n} device(s).");

            return self::SUCCESS;
        }

        if ($this->option('scan')) {
            $n = $this->dispatchScans();
            $this->info("Dispatched scan for {$n} due subnet(s).");

            return self::SUCCESS;
        }

        if ($this->option('partitions')) {
            // Run synchronously so it's useful from cron without a queue worker.
            $r = app(ManageHistoryPartitions::class)();
            $this->info("History partitions - created {$r['created']}, dropped {$r['dropped']}.");

            return self::SUCCESS;
        }

        if ($this->option('once')) {
            $p = app(PingDispatcher::class)->dispatch();
            $n = $this->dispatchPoll();
            $m = $this->dispatchMetrics();
            $this->info("Dispatched {$p} ping + {$n} poll + {$m} metrics batch job(s).");

            return self::SUCCESS;
        }

        $pingInterval = max(1, (int) config('mymate.ping.interval', 5));
        $pollInterval = max(1, (int) config('mymate.poll.interval', 12));
        $discoverInterval = max(60, (int) config('mymate.poll.discover_interval', 600));
        $scanCheckInterval = max(5, (int) config('mymate.discovery.check_interval', 30));
        $historyInterval = max(60, (int) config('mymate.history.maintain_interval', 3600));

        $this->info("mymate:loop started - ping ~{$pingInterval}s, throughput ~{$pollInterval}s, scan check ~{$scanCheckInterval}s. Ctrl+C to stop.");
        EngineLog::info('loop: started', [
            'ping_interval' => $pingInterval,
            'poll_interval' => $pollInterval,
            'discover_interval' => $discoverInterval,
            'scan_check_interval' => $scanCheckInterval,
            'history_interval' => $historyInterval,
            'shards' => max(1, (int) config('mymate.poll.shards', 16)),
        ]);

        // Discover once + ensure history partitions exist before the first poll writes.
        $this->dispatchDiscovery();
        ManageHistoryPartitionsJob::dispatch();
        $settings = app(Settings::class);
        $dispatcher = app(PingDispatcher::class);
        $lastPoll = 0.0;
        $lastMetrics = 0.0;
        $lastDiscover = microtime(true);
        $lastScanCheck = 0.0;
        $lastHistory = microtime(true);

        while (true) {
            // Re-read cadences each cycle so a Settings change applies without a restart.
            $pingInterval = max(1, $settings->getInt('ping.interval', 5));
            $pollInterval = max(1, $settings->getInt('poll.interval', 12));
            $metricsInterval = max(5, (int) config('mymate.device_metrics.interval', 30));
            $discoverInterval = max(60, $settings->getInt('poll.discover_interval', 600));
            $scanCheckInterval = max(5, $settings->getInt('discovery.check_interval', 30));
            $historyInterval = max(60, $settings->getInt('history.maintain_interval', 3600));

            // The base tick is the global ping interval, unless a map sets a faster override -
            // then we tick at that faster rate and each interval bucket is swept when it's due
            // (see PingDispatcher). No override -> pingBase == pingInterval, loop unchanged.
            $pingBase = $dispatcher->baseInterval($pingInterval);

            $dispatcher->dispatch();
            $this->heartbeat(); // liveness signal for the Settings system-status panel

            $now = microtime(true);
            if ($now - $lastPoll >= $pollInterval) {
                EngineLog::debug('loop: poll dispatched', ['shards' => $this->dispatchPoll()]);
                // Publish poll/scan work to online remote agents (they poll their own network).
                $agents = app(\App\Actions\Agent\DispatchAgentJobs::class)();
                if ($agents > 0) {
                    EngineLog::debug('loop: agent jobs dispatched', ['agents' => $agents]);
                }
                EvaluateAlertsJob::dispatch(); // evaluate alert policies on the poll cadence
                // Service probes (HTTP/TCP, GitHub #19) - dispatched on the poll cadence; each
                // probe only actually runs when its own interval is due (a no-op if none exist).
                app(PollDispatcher::class)->dispatchProbes();
                $lastPoll = $now;
            }
            // Device resource metrics (cpu/mem/temp) on their own slower cadence, plus any
            // custom SNMP sensors (a no-op when none are defined).
            if (config('mymate.device_metrics.enabled', true) && $now - $lastMetrics >= $metricsInterval) {
                EngineLog::debug('loop: metrics dispatched', ['shards' => $this->dispatchMetrics()]);
                app(PollDispatcher::class)->dispatchSensors();
                $lastMetrics = $now;
            }
            if ($now - $lastDiscover >= $discoverInterval) {
                EngineLog::debug('loop: discovery dispatched', ['devices' => $this->dispatchDiscovery()]);
                $lastDiscover = $now;
            }
            // The per-subnet scan_interval_s sets the real cadence; we just check who's
            // due on this granularity and dispatch onto the isolated `scan` queue.
            if ($now - $lastScanCheck >= $scanCheckInterval) {
                EngineLog::debug('loop: scan check', ['dispatched' => $this->dispatchScans()]);
                $lastScanCheck = $now;
            }
            // Roll history partitions forward + drop expired ones (light DDL, off-tick).
            if ($now - $lastHistory >= $historyInterval) {
                ManageHistoryPartitionsJob::dispatch();
                EngineLog::debug('loop: history partitions dispatched');
                $lastHistory = $now;
            }

            sleep($pingBase);
        }
    }

    /** Dispatch the sharded throughput batch jobs. Returns shard count. */
    /** Cache key holding the unix time of the last loop tick (see SystemStatus). */
    public const HEARTBEAT_KEY = 'mymate.loop.last_tick';

    /** Stamp a liveness heartbeat each tick; a Redis blip must never kill the loop. */
    private function heartbeat(): void
    {
        try {
            \Illuminate\Support\Facades\Cache::put(self::HEARTBEAT_KEY, now()->timestamp, now()->addHour());
        } catch (\Throwable) {
            // best-effort only
        }
    }

    private function dispatchPoll(): int
    {
        return app(PollDispatcher::class)->dispatch();
    }

    /** Dispatch the sharded device-metrics (cpu/mem/temp) batch jobs. Returns shard count. */
    private function dispatchMetrics(): int
    {
        return app(PollDispatcher::class)->dispatchMetrics();
    }

    /** Dispatch an interface-discovery job per pollable device. */
    private function dispatchDiscovery(): int
    {
        return $this->eachPollable(fn (int $id) => DiscoverInterfacesJob::dispatch($id));
    }

    /**
     * Dispatch a scan job for every enabled subnet that's due. "Due" =
     * never scanned, or last scanned longer ago than its own scan_interval_s. The
     * per-subnet overlap lock means a still-running scan simply skips this tick.
     */
    private function dispatchScans(): int
    {
        $now = now();

        // Only central (agent-less) subnets are scanned from here; agent subnets are
        // scanned by their agent inside the management network.
        $due = Subnet::where('enabled', true)->whereNull('agent_id')->get()->filter(
            fn (Subnet $s): bool => $s->last_scanned_at === null
                || $s->last_scanned_at->copy()->addSeconds(max(1, $s->scan_interval_s))->lessThanOrEqualTo($now)
        );

        $due->each(fn (Subnet $s) => ScanSubnetJob::dispatch($s->id));

        return $due->count();
    }

    /**
     * Run $dispatch for each device we have a throughput driver for. Both throughput
     * methods have a driver, so periodic (re)discovery covers SNMP **and** RouterOS
     *.
     * Ping-only devices are excluded - no driver, so
     * neither throughput-polled nor discovered (they still get pinged elsewhere).
     */
    private function eachPollable(callable $dispatch): int
    {
        $ids = Device::whereNull('agent_id') // agent devices are (re)discovered by their agent
            ->whereIn('poll_method', PollMethod::throughputMethods())->pluck('id');
        $ids->each($dispatch);

        return $ids->count();
    }
}
