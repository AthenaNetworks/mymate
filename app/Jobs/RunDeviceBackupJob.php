<?php

namespace App\Jobs;

use App\Actions\Backup\RunDeviceBackup;
use App\Models\Device;
use App\Support\EngineLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Thin queue envelope: back up one device on the isolated `backup` queue so a slow SSH
 * capture never delays polling/discovery/upgrades. `tries=1` (a backup
 * is safe to just re-run on the next schedule, and we don't want to hammer a wedged device)
 * + a per-device overlap lock. One job per device - "back up all" fans out many of these.
 */
class RunDeviceBackupJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $deviceId)
    {
        $this->onQueue('backup');
    }

    public function handle(RunDeviceBackup $backup): void
    {
        $device = Device::find($this->deviceId);
        if ($device !== null) {
            $backup($device);
        }
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("backup-device-{$this->deviceId}"))->dontRelease()->expireAfter(600)];
    }

    public function failed(Throwable $e): void
    {
        EngineLog::error('backup: job failed', [
            'device_id' => $this->deviceId,
            'exception' => $e::class,
            'error' => $e->getMessage(),
        ]);
    }
}
