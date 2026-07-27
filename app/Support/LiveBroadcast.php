<?php

namespace App\Support;

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
