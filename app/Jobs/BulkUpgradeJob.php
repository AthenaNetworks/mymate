<?php

namespace App\Jobs;

use App\Actions\Devices\RunBulkUpgrade;
use App\Support\EngineLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Thin queue envelope for an **ordered** bulk upgrade: one long-running job on the
 * isolated `upgrade` queue that walks the selection downstream-first, waiting for
 * each device to recover before the next (the per-device reboot-waits are why the
 * `upgrade` supervisor has a long timeout). `tries=1` + a single overlap lock so
 * two ordered runs can't interleave.
 */
class BulkUpgradeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** @param list<int> $deviceIds */
    public function __construct(
        public array $deviceIds,
        public bool $preserveOrder = false,
        public ?string $version = null,
        public string $source = 'mikrotik',
    ) {
        $this->onQueue('upgrade');
    }

    public function handle(RunBulkUpgrade $run): void
    {
        $run($this->deviceIds, $this->preserveOrder, $this->version, $this->source);
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('bulk-upgrade'))->dontRelease()];
    }

    public function failed(Throwable $e): void
    {
        EngineLog::error('bulk-upgrade: job failed', [
            'count' => count($this->deviceIds),
            'exception' => $e::class,
            'error' => $e->getMessage(),
        ]);
    }
}
