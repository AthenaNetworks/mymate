<?php

namespace App\Support;

use App\Events\Concerns\ScopableLiveEvent;

/**
 * Fire a live-update broadcast inline (the events are ShouldBroadcastNow), tolerating a broker or
 * Reverb hiccup.
 *
 * These events (interface util, device metrics, latency, status) are ephemeral: one is emitted
 * every poll tick and the next tick supersedes it, so there is no value in queuing them - a
 * delayed frame is already stale. Historically they were queued (ShouldBroadcast), and on a large
 * fleet the per-tick stream out-ran the single queue draining it and piled up in Redis until the
 * box OOM-killed redis-server (GitHub, "Out of memory: Killed process redis-server"). Sending them
 * inline keeps Redis out of the hot path entirely.
 *
 * Because they now send from the poll workers, a momentary Reverb outage must not fail the poll
 * (the sample is already written to Postgres) - so any broadcast error is swallowed and logged,
 * exactly the decoupling the queue used to give us, without the unbounded backlog.
 */
class LiveBroadcast
{
    public static function send(object $event): void
    {
        self::dispatch($event);

        // The shared channel is for unrestricted operators only. Everyone confined to some maps
        // gets their own copy carrying just their devices (or nothing, if none are in it).
        if ($event instanceof ScopableLiveEvent) {
            try {
                $audience = RestrictedAudience::members();
            } catch (\Throwable $e) {
                EngineLog::warning('broadcast: restricted audience unavailable', ['error' => $e->getMessage()]);

                return;
            }
            foreach ($audience as $userId => $visible) {
                $copy = $event->scopedTo($visible, RestrictedAudience::channelFor($userId));
                if ($copy !== null) {
                    self::dispatch($copy);
                }
            }
        }
    }

    /**
     * Send a batched event (one built from a `$devices` list of per-device frames) as however many
     * events keep each one under the util broadcast's byte budget. Reverb refuses anything over its
     * message size, so a metrics tick for a big shard used to go nowhere at all. PollInterfaces
     * has its own, stricter version of this for the util frames.
     *
     * @param  callable(list<array<string, mixed>>): object  $make
     * @param  list<array<string, mixed>>  $frames
     */
    public static function sendFrames(callable $make, array $frames): void
    {
        $cap = max(1, (int) config('mymate.poll.broadcast.max_bytes_per_event', 6000));
        $wrapper = 64; // the {"devices":[...],"device_count":N} around them
        $chunk = [];
        $bytes = $wrapper;
        foreach ($frames as $frame) {
            $b = strlen((string) json_encode($frame)) + 1;
            if ($chunk !== [] && $bytes + $b > $cap) {
                self::send($make($chunk));
                $chunk = [];
                $bytes = $wrapper;
            }
            $chunk[] = $frame;
            $bytes += $b;
        }
        if ($chunk !== []) {
            self::send($make($chunk));
        }
    }

    private static function dispatch(object $event): void
    {
        try {
            event($event);
        } catch (\Throwable $e) {
            EngineLog::warning('broadcast: live update not delivered', [
                'event' => class_basename($event),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
