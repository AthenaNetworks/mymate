<?php

namespace App\Jobs;

use App\Actions\Polling\PollSensors;
use App\Support\EngineLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Queue envelope: one custom-sensor poll tick for one shard of the fleet - the sibling of
 * PollDeviceMetricsBatchJob. Per-shard overlap lock gives backpressure and cross-daemon safety.
 */
class PollSensorsBatchJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** @param  list<int>  $deviceIds */
    public function __construct(public int $shard, public array $deviceIds)
    {
        $this->onQueue('poll');
    }

    public function handle(PollSensors $poll): void
    {
        $poll($this->deviceIds);
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("sensors-shard-{$this->shard}"))->dontRelease()->expireAfter(60)];
    }

    public function failed(Throwable $e): void
    {
        EngineLog::error('sensors: batch job failed', [
            'shard' => $this->shard,
            'devices' => count($this->deviceIds),
            'exception' => $e::class,
            'error' => $e->getMessage(),
        ]);
    }
}
