<?php

namespace App\Jobs;

use App\Actions\Alerts\EvaluateAlerts;
use App\Support\EngineLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Thin envelope: evaluate alert policies on the `poll` queue. Dispatched
 * by the loop on the poll cadence; `tries=1` + an overlap lock so two evaluations
 * never run at once (which would double-fire).
 */
class EvaluateAlertsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('poll');
    }

    public function handle(EvaluateAlerts $evaluate): void
    {
        $evaluate();
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('evaluate-alerts'))->dontRelease()->expireAfter(60)];
    }

    public function failed(Throwable $e): void
    {
        EngineLog::error('alert: evaluation job failed', ['error' => $e->getMessage()]);
    }
}
