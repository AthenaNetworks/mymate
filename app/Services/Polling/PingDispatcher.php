<?php

namespace App\Services\Polling;

use App\Jobs\PingSweepJob;
use App\Models\Device;

/**
 * Shards the up/down sweep across N ping jobs by `crc32(device_id) % shards` and dispatches one
 * PingSweepJob per non-empty shard - the same pattern {@see PollDispatcher} uses for throughput.
 *
 * Why: a single fping over the whole fleet is fine for hundreds of devices but blows past the
 * process timeout at tens of thousands (fping paces its sends, so time grows with host count).
 * Sharding keeps each fping small and lets the shards run in parallel across the `ping` workers,
 * so the wall-clock of a sweep is one shard's time, not the whole fleet's. shards=1 (the default)
 * preserves the original single-sweep behaviour for small installs.
 *
 * Scale a large fleet by raising `mymate.ping.shards` and the `supervisor-ping` worker count
 * (MYMATE_PING_PROCESSES) together - roughly shards ~= fleet x per-host-seconds / ping interval.
 */
class PingDispatcher
{
    /** @return int number of ping jobs dispatched (non-empty shards) */
    public function dispatch(): int
    {
        $shards = max(1, (int) config('mymate.ping.shards', 1));

        // Fast path: one sweep over the whole fleet (unchanged behaviour, no id list to carry).
        if ($shards === 1) {
            $any = Device::where('monitored', true)->whereNull('agent_id')->exists();
            if (! $any) {
                return 0;
            }
            PingSweepJob::dispatch(); // deviceIds=null -> whole fleet

            return 1;
        }

        // Skip monitoring-paused devices and agent-assigned ones (their agent pings them).
        $ids = Device::where('monitored', true)->whereNull('agent_id')->pluck('id');
        if ($ids->isEmpty()) {
            return 0;
        }

        /** @var array<int, list<int>> $byShard */
        $byShard = [];
        foreach ($ids as $id) {
            $byShard[crc32((string) $id) % $shards][] = (int) $id;
        }

        foreach ($byShard as $shard => $shardIds) {
            PingSweepJob::dispatch($shardIds, $shard);
        }

        return count($byShard);
    }
}
