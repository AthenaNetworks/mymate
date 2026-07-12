<?php

namespace App\Console\Commands;

use App\Actions\History\ManageHistoryPartitions;
use App\Enums\PollMethod;
use App\Jobs\DiscoverInterfacesJob;
use App\Jobs\EvaluateAlertsJob;
use App\Jobs\ManageHistoryPartitionsJob;
use App\Jobs\PingSweepJob;
use App\Jobs\ScanSubnetJob;
use App\Models\Device;
use App\Models\Subnet;
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
            PingSweepJob::dispatch();
            $n = $this->dispatchPoll();
            $this->info("Dispatched one ping sweep + {$n} poll batch job(s).");

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
        $lastPoll = 0.0;
        $lastDiscover = microtime(true);
        $lastScanCheck = 0.0;
        $lastHistory = microtime(true);

        while (true) {
            // Re-read cadences each cycle so a Settings change applies without a restart.
            $pingInterval = max(1, $settings->getInt('ping.interval', 5));
            $pollInterval = max(1, $settings->getInt('poll.interval', 12));
            $discoverInterval = max(60, $settings->getInt('poll.discover_interval', 600));
            $scanCheckInterval = max(5, $settings->getInt('discovery.check_interval', 30));
            $historyInterval = max(60, $settings->getInt('history.maintain_interval', 3600));

            PingSweepJob::dispatch();

            $now = microtime(true);
            if ($now - $lastPoll >= $pollInterval) {
                EngineLog::debug('loop: poll dispatched', ['shards' => $this->dispatchPoll()]);
                // Publish poll/scan work to online remote agents (they poll their own network).
                $agents = app(\App\Actions\Agent\DispatchAgentJobs::class)();
                if ($agents > 0) {
                    EngineLog::debug('loop: agent jobs dispatched', ['agents' => $agents]);
                }
                EvaluateAlertsJob::dispatch(); // evaluate alert policies on the poll cadence
                $lastPoll = $now;
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

            sleep($pingInterval);
        }
    }

    /** Dispatch the sharded throughput batch jobs. Returns shard count. */
    private function dispatchPoll(): int
    {
        return app(PollDispatcher::class)->dispatch();
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
