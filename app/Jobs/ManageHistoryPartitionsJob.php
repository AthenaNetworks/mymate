<?php

namespace App\Jobs;

use App\Actions\History\ManageHistoryPartitions;
use App\Support\EngineLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Thin queue envelope for history partition maintenance. Dispatched by
 * the loop on a slow cadence and by the daily scheduler; the overlap lock keeps the
 * two from colliding. Light DDL - runs on the default/scan pool, off the poll path.
 */
class ManageHistoryPartitionsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(ManageHistoryPartitions $manage): void
    {
        EngineLog::debug('history: partitions maintained', $manage());
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('history-partitions'))->dontRelease()->expireAfter(120)];
    }

    public function failed(Throwable $e): void
    {
        EngineLog::error('history: partition job failed', [
            'exception' => $e::class,
            'error' => $e->getMessage(),
        ]);
    }
}
