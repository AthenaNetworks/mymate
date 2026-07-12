<?php

namespace App\Jobs;

use App\Actions\Polling\PollInterfaces;
use App\Support\EngineLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Thin queue envelope: an on-demand throughput tick for a **single** device (e.g.
 * just after a discovery candidate is promoted, or a manual "poll now"). The fleet
 * loop uses PollInterfacesBatchJob instead; this is the targeted one-off path.
 */
class PollInterfacesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $deviceId)
    {
        $this->onQueue('poll');
    }

    public function handle(PollInterfaces $poll): void
    {
        $poll([$this->deviceId]);
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("poll-device-{$this->deviceId}"))->dontRelease()->expireAfter(30)];
    }

    public function failed(Throwable $e): void
    {
        EngineLog::error('poll: device job failed', [
            'device_id' => $this->deviceId,
            'exception' => $e::class,
            'error' => $e->getMessage(),
        ]);
    }
}
