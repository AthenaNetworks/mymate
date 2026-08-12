<?php

namespace App\Services\Polling;

use App\Jobs\PingSweepJob;
use App\Models\Device;
use App\Support\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Shards the up/down sweep across N ping jobs by `crc32(device_id) % shards` and dispatches one
 * PingSweepJob per non-empty shard - the same pattern {@see PollDispatcher} uses for throughput.
 *
 * Why shard: a single fping over the whole fleet is fine for hundreds of devices but blows past the
 * process timeout at tens of thousands (fping paces its sends, so time grows with host count).
 * Sharding keeps each fping small and lets the shards run in parallel across the `ping` workers, so
 * the wall-clock of a sweep is one shard's time, not the whole fleet's.
 *
 * Per-map cadence (GitHub #32): a map may set its own `ping_interval`, so a device inherits the
 * fastest interval of the maps it's on, else the global one. When no map sets an override the whole
 * fleet pings on the single global interval exactly as before - the cadence path is skipped
 * entirely. When one does, the loop ticks at the fastest interval (see baseInterval) and each
 * interval "bucket" is swept only when its own interval has elapsed.
 */
class PingDispatcher
{
    /** @return int number of ping jobs dispatched this tick */
    public function dispatch(): int
    {
        $overrides = $this->deviceIntervalOverrides();

        // Nobody set a per-map interval -> original single-cadence behaviour (incl. the cheap
        // whole-fleet fast path when unsharded). No extra queries or state in the common case.
        if ($overrides === []) {
            return $this->dispatchAll();
        }

        return $this->dispatchByCadence($overrides);
    }

    /** The original behaviour: one sweep (or one per shard) over the whole monitored fleet. */
    private function dispatchAll(): int
    {
        $shards = max(1, (int) config('mymate.ping.shards', 1));

        // Fast path: one sweep over the whole fleet (unchanged behaviour, no id list to carry).
        if ($shards === 1) {
            if (! Device::where('monitored', true)->whereNull('agent_id')->exists()) {
                return 0;
            }
            PingSweepJob::dispatch(); // deviceIds=null -> whole fleet

            return 1;
        }

        $ids = Device::where('monitored', true)->whereNull('agent_id')->pluck('id')->all();

        return $this->shardAndDispatch(array_map('intval', $ids));
    }

    /**
     * Bucket monitored devices by their effective interval and this tick sweep only the buckets
     * whose interval has elapsed since they last ran. Bucket timing is tracked in the cache (keyed
     * by interval), so it survives across the many dispatch() calls the loop makes; after a restart
     * every bucket simply runs on the first tick, which is harmless.
     *
     * @param  array<int, int>  $overrides  device id => fastest map ping_interval covering it
     */
    private function dispatchByCadence(array $overrides): int
    {
        $global = max(1, app(Settings::class)->getInt('ping.interval', 5));

        $ids = Device::where('monitored', true)->whereNull('agent_id')->pluck('id');
        if ($ids->isEmpty()) {
            return 0;
        }

        /** @var array<int, list<int>> $buckets  interval seconds => device ids */
        $buckets = [];
        foreach ($ids as $id) {
            $interval = $overrides[$id] ?? $global;
            $buckets[$interval][] = (int) $id;
        }

        $now = microtime(true);
        $due = [];
        foreach ($buckets as $interval => $bucketIds) {
            $key = "ping:cadence:last:{$interval}";
            if ($now - (float) Cache::get($key, 0.0) >= $interval) {
                $due = array_merge($due, $bucketIds);
                Cache::put($key, $now, now()->addDay());
            }
        }

        return $due === [] ? 0 : $this->shardAndDispatch($due);
    }

    /**
     * Shard a device id set the way the fleet sweep does and dispatch one job per non-empty shard.
     * shards=1 collapses to a single sweep over exactly this set.
     *
     * @param  list<int>  $ids
     */
    private function shardAndDispatch(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        $shards = max(1, (int) config('mymate.ping.shards', 1));

        /** @var array<int, list<int>> $byShard */
        $byShard = [];
        foreach ($ids as $id) {
            $byShard[crc32((string) $id) % $shards][] = $id;
        }

        foreach ($byShard as $shard => $shardIds) {
            PingSweepJob::dispatch($shardIds, $shard);
        }

        return count($byShard);
    }

    /**
     * device id => the fastest map ping_interval override covering it. Empty when no map sets one,
     * which is the signal to keep the original single-cadence behaviour.
     *
     * @return array<int, int>
     */
    private function deviceIntervalOverrides(): array
    {
        $rows = DB::table('device_map_positions as dmp')
            ->join('maps as m', 'm.id', '=', 'dmp.map_id')
            ->whereNotNull('m.ping_interval')
            ->groupBy('dmp.device_id')
            ->get(['dmp.device_id', DB::raw('min(m.ping_interval) as iv')]);

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->device_id] = (int) $row->iv;
        }

        return $out;
    }

    /** The smallest map ping_interval override, or null when none is set. */
    public function minMapOverride(): ?int
    {
        $min = DB::table('maps')->whereNotNull('ping_interval')->min('ping_interval');

        return $min === null ? null : (int) $min;
    }

    /**
     * The base loop tick for pings: the fastest cadence in play, so every bucket can be honoured.
     * With no override this is just the global interval and the loop is unchanged.
     */
    public function baseInterval(int $global): int
    {
        $override = $this->minMapOverride();

        return max(1, $override === null ? $global : min($global, $override));
    }
}
