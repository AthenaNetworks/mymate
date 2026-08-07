<?php

namespace App\Services\Polling;

use App\Enums\PollMethod;
use App\Jobs\PollDeviceMetricsBatchJob;
use App\Jobs\PollInterfacesBatchJob;
use App\Jobs\PollProbesBatchJob;
use App\Jobs\PollSensorsBatchJob;
use App\Models\Device;
use App\Models\Probe;
use App\Models\Sensor;

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

    /**
     * Custom SNMP sensors: shard the SNMP fleet and dispatch a sensor-poll job per shard.
     * No-op (no jobs) when no sensors are defined, so it's cheap to call every metrics tick.
     *
     * @return int number of batch jobs dispatched (non-empty shards)
     */
    public function dispatchSensors(): int
    {
        if (! Sensor::where('enabled', true)->exists()) {
            return 0;
        }

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
            PollSensorsBatchJob::dispatch($shard, $shardIds);
        }

        return count($byShard);
    }

    /**
     * Service probes (GitHub #19): shard the devices that carry an enabled probe and dispatch a
     * probe-poll job per shard. No-op when nothing has a probe, so it's cheap to call every tick.
     * Runs from the central host, so agent-assigned devices are skipped (their network is remote).
     *
     * @return int number of batch jobs dispatched (non-empty shards)
     */
    public function dispatchProbes(): int
    {
        $deviceIds = Probe::where('enabled', true)
            ->whereHas('device', fn ($q) => $q->where('monitored', true)->whereNull('agent_id'))
            ->pluck('device_id')->unique()->values();
        if ($deviceIds->isEmpty()) {
            return 0;
        }

        $shards = max(1, (int) config('mymate.poll.shards', 16));

        /** @var array<int, list<int>> $byShard */
        $byShard = [];
        foreach ($deviceIds as $id) {
            $byShard[crc32((string) $id) % $shards][] = (int) $id;
        }

        foreach ($byShard as $shard => $shardIds) {
            PollProbesBatchJob::dispatch($shard, $shardIds);
        }

        return count($byShard);
    }
}
