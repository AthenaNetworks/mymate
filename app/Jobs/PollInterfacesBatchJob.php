<?php

namespace App\Jobs;

use App\Actions\Polling\PollInterfaces;
use App\Support\EngineLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Thin queue envelope: one throughput tick for one **shard** of the fleet (
 * scale-out). The loop dispatches one of these per shard each tick; the per-shard
 * overlap lock gives backpressure (a slow shard skips its next tick rather than
 * piling up) **and** cross-daemon safety (two dispatchers can't double-run a shard).
 */
class PollInterfacesBatchJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** @param  list<int>  $deviceIds */
    public function __construct(public int $shard, public array $deviceIds)
    {
        $this->onQueue('poll');
    }

    public function handle(PollInterfaces $poll): void
    {
        $poll($this->deviceIds);
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("poll-shard-{$this->shard}"))->dontRelease()->expireAfter(60)];
    }

    public function failed(Throwable $e): void
    {
        EngineLog::error('poll: batch job failed', [
            'shard' => $this->shard,
            'devices' => count($this->deviceIds),
            'exception' => $e::class,
            'error' => $e->getMessage(),
        ]);
    }
}
