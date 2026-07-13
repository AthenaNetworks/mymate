<?php

namespace App\Services\Polling;

use App\Enums\PollMethod;
use App\Jobs\PollDeviceMetricsBatchJob;
use App\Jobs\PollInterfacesBatchJob;
use App\Models\Device;

/**
 * Shards the pollable fleet into N batch jobs by `crc32(device_id) % shards` and
 * dispatches one PollInterfacesBatchJob per non-empty shard.
 *
 * Sharding is deterministic (stable shard key per device + config N), so the
 * per-shard overlap lock on the job gives backpressure and cross-daemon safety.
 * Scale the fleet by raising `mymate.poll.shards` and adding `poll` workers.
 */
class PollDispatcher
{
    /** @return int number of batch jobs dispatched (non-empty shards) */
    public function dispatch(): int
    {
        $shards = max(1, (int) config('mymate.poll.shards', 16));

        // The throughput driver is chosen per device by poll_method; a device with
        // no/unreachable credential just fails fast and is isolated by the orchestrator.
        // Only poll monitored devices (monitored=false -> paused/mock), and only those
        // with an actual throughput method - ping-only devices (poll_method=none,
        // ) have no driver, so dispatching them would just throw + log a
        // spurious `poll: device poll failed` every tick, the exact noise this avoids.
        $ids = Device::where('monitored', true)
            ->whereNull('agent_id') // agent-assigned devices are polled by their agent
            ->whereIn('poll_method', PollMethod::throughputMethods())
            ->pluck('id');
        if ($ids->isEmpty()) {
            return 0;
        }

        /** @var array<int, list<int>> $byShard */
        $byShard = [];
        foreach ($ids as $id) {
            $byShard[crc32((string) $id) % $shards][] = (int) $id;
        }

        foreach ($byShard as $shard => $shardIds) {
            PollInterfacesBatchJob::dispatch($shard, $shardIds);
        }

        return count($byShard);
    }

    /**
     * Shard the same pollable fleet into device-metrics (cpu/mem/temp) batch jobs. Same
     * shard key as throughput so a device's metrics and throughput land on matching shard
     * numbers; dispatched on the (slower) device-metrics cadence from the loop.
     *
     * @return int number of batch jobs dispatched (non-empty shards)
     */
    public function dispatchMetrics(): int
    {
        $shards = max(1, (int) config('mymate.poll.shards', 16));

        $ids = Device::where('monitored', true)
            ->whereNull('agent_id')
            ->whereIn('poll_method', PollMethod::throughputMethods())
            ->pluck('id');
        if ($ids->isEmpty()) {
            return 0;
        }

        /** @var array<int, list<int>> $byShard */
        $byShard = [];
        foreach ($ids as $id) {
            $byShard[crc32((string) $id) % $shards][] = (int) $id;
        }

        foreach ($byShard as $shard => $shardIds) {
            PollDeviceMetricsBatchJob::dispatch($shard, $shardIds);
        }

        return count($byShard);
    }
}
