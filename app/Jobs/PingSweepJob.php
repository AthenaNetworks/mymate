<?php

namespace App\Jobs;

use App\Actions\Polling\PingFleet;
use App\Support\EngineLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

// Thin queue envelope: configures the queue/overlap and delegates to the Action.
// One job per shard (PingDispatcher fans them out); shard 0 with deviceIds=null = whole fleet.
class PingSweepJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * @param  list<int>|null  $deviceIds  the shard's device ids (null = whole fleet, one sweep)
     * @param  int  $shard  shard index, used only to key the overlap lock so shards don't block each other
     */
    public function __construct(
        private ?array $deviceIds = null,
        private int $shard = 0,
    ) {
        $this->onQueue('ping');
    }

    public function handle(PingFleet $pingFleet): void
    {
        $pingFleet($this->deviceIds);
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        // Never let a shard's sweeps pile up if one runs long; skip (don't release) overlaps.
        // Keyed per shard so shards run in parallel; expiry tracks the fping process timeout so
        // a wedged sweep can't hold the lock forever.
        $expire = max(30, (int) config('mymate.ping.process_timeout', 30) + 5);

        return [(new WithoutOverlapping('ping-sweep-'.$this->shard))->dontRelease()->expireAfter($expire)];
    }

    public function failed(Throwable $e): void
    {
        EngineLog::error('ping: sweep job failed', [
            'shard' => $this->shard,
            'exception' => $e::class,
            'error' => $e->getMessage(),
        ]);
    }
}
