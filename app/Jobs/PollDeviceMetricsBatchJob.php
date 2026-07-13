<?php

namespace App\Jobs;

use App\Actions\Polling\PollDeviceMetrics;
use App\Support\EngineLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Queue envelope: one device-metrics tick for one shard of the fleet - the cpu/mem/temp
 * sibling of PollInterfacesBatchJob. Per-shard overlap lock gives backpressure (a slow
 * shard skips its next tick) and cross-daemon safety.
 */
class PollDeviceMetricsBatchJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** @param  list<int>  $deviceIds */
    public function __construct(public int $shard, public array $deviceIds)
    {
        $this->onQueue('poll');
    }

    public function handle(PollDeviceMetrics $poll): void
    {
        $poll($this->deviceIds);
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("metrics-shard-{$this->shard}"))->dontRelease()->expireAfter(60)];
    }

    public function failed(Throwable $e): void
    {
        EngineLog::error('metrics: batch job failed', [
            'shard' => $this->shard,
            'devices' => count($this->deviceIds),
            'exception' => $e::class,
            'error' => $e->getMessage(),
        ]);
    }
}
