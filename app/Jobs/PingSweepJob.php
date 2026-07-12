<?php

namespace App\Jobs;

use App\Actions\Polling\PingFleet;
use App\Support\EngineLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

// Thin queue envelope: configures the queue/overlap and delegates to the Action.
class PingSweepJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('ping');
    }

    public function handle(PingFleet $pingFleet): void
    {
        $pingFleet();
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        // Never let sweeps pile up if one runs long; skip (don't release) overlaps.
        return [(new WithoutOverlapping('ping-sweep'))->dontRelease()->expireAfter(30)];
    }

    public function failed(Throwable $e): void
    {
        EngineLog::error('ping: sweep job failed', [
            'exception' => $e::class,
            'error' => $e->getMessage(),
        ]);
    }
}
