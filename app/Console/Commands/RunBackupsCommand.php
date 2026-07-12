<?php

namespace App\Console\Commands;

use App\Jobs\RunDeviceBackupJob;
use App\Models\Device;
use Illuminate\Console\Command;

/**
 * Dispatch config-backup jobs. Fans out one {@see RunDeviceBackupJob}
 * per device onto the isolated `backup` queue - the actual SSH capture happens in Rusted,
 * off the request/scheduler thread. Driven either on a schedule (see routes/console.php) or
 * on demand:
 *   php artisan mymate:backup:run --all        # every backup-enabled device
 *   php artisan mymate:backup:run --device=42  # one device (ignores its enabled flag)
 */
class RunBackupsCommand extends Command
{
    protected $signature = 'mymate:backup:run {--all : back up every backup-enabled device} {--device= : back up a single device by id}';

    protected $description = 'Queue config backups (via the Rusted engine) for backup-enabled devices';

    public function handle(): int
    {
        $deviceId = $this->option('device');

        if ($deviceId !== null) {
            $device = Device::find((int) $deviceId);
            if ($device === null) {
                $this->error("No device with id {$deviceId}.");

                return self::FAILURE;
            }
            RunDeviceBackupJob::dispatch($device->id);
            $this->info("Queued a backup for {$device->name}.");

            return self::SUCCESS;
        }

        if (! $this->option('all')) {
            $this->error('Pass --all to back up every enabled device, or --device=<id> for one.');

            return self::FAILURE;
        }

        $ids = Device::query()->where('backup_enabled', true)->pluck('id');
        foreach ($ids as $id) {
            RunDeviceBackupJob::dispatch($id);
        }
        $this->info("Queued backups for {$ids->count()} device(s).");

        return self::SUCCESS;
    }
}
