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

    /**
     * Sized to the selection: every device can take a reboot-wait plus the upgrade itself, so a
     * flat worker timeout (900s) killed any run of more than about three devices part way.
     */
    public int $timeout;

    /** Per device on top of the reboot-wait: package fetch/install and the reboot command. */
    private const PER_DEVICE_EXTRA_S = 180;

    /** Never past the long-queue retry_after (config/queue.php `redis-long`). */
    private const MAX_TIMEOUT_S = 21600;

    /** @param list<int> $deviceIds */
    public function __construct(
        public array $deviceIds,
        public bool $preserveOrder = false,
        public ?string $version = null,
        public string $source = 'mikrotik',
    ) {
        $this->onQueue('upgrade');
        $perDevice = max(1, (int) config('mymate.upgrade.reboot_wait_s', 300)) + self::PER_DEVICE_EXTRA_S;
        $this->timeout = min(self::MAX_TIMEOUT_S, max(900, count($deviceIds) * $perDevice));
    }

    public function handle(RunBulkUpgrade $run): void
    {
        $run($this->deviceIds, $this->preserveOrder, $this->version, $this->source);
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        // The lock expires just after the job's own timeout: without it a run killed by the
        // timeout (or a worker crash) held the lock forever and every later bulk upgrade was
        // silently dropped.
        return [(new WithoutOverlapping('bulk-upgrade'))->dontRelease()->expireAfter($this->timeout + 60)];
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
