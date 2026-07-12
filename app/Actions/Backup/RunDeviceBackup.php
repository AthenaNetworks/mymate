<?php

namespace App\Actions\Backup;

use App\Enums\BackupStatus;
use App\Models\Device;
use App\Services\Backup\RustedClient;
use App\Support\EngineLog;
use Illuminate\Support\Facades\Cache;

/**
 * Run a config backup for one device now: ensure Rusted knows the
 * device, trigger a synchronous backup, and mirror the result onto the device so the UI
 * can show "last backup: ok, 2 min ago" without re-querying Rusted.
 *
 * The per-device lock lives here (not just on the Job) so *every* call path is covered -
 * the lesson from bulk upgrades. `tries=1` on the Job means a failed backup marks
 * the device `failed` (via {@see mark()}) rather than silently retrying.
 */
class RunDeviceBackup
{
    public function __construct(
        private RustedClient $client,
        private RegisterBackupDevice $register,
    ) {}

    public function __invoke(Device $device): void
    {
        $lock = Cache::lock("device-backup-inflight:{$device->id}", 300);
        if (! $lock->get()) {
            return; // a backup for this device is already running - don't stack them
        }

        try {
            $this->mark($device, BackupStatus::Pending, null);

            ($this->register)($device); // idempotent upsert into Rusted

            $result = $this->client->backup(RegisterBackupDevice::rustedName($device));

            $status = BackupStatus::fromRusted($result['status'] ?? null);
            $this->mark(
                $device,
                $status,
                $this->messageFrom($result),
                is_string($result['commit'] ?? null) ? $result['commit'] : null,
            );
        } catch (\Throwable $e) {
            $this->mark($device, BackupStatus::Failed, $this->cleanError($e->getMessage()));
            EngineLog::error('backup: device backup failed', [
                'device_id' => $device->id,
                'exception' => $e::class,
                'error' => $e->getMessage(), // message only - RustedClient never puts secrets in these
            ]);
            throw $e; // let the Job's failed() see it too
        } finally {
            $lock->release();
        }
    }

    /** Persist the run outcome onto the device (the cached mirror the UI reads). */
    private function mark(Device $device, BackupStatus $status, ?string $message, ?string $commit = null): void
    {
        $device->forceFill([
            'backup_status' => $status,
            'backup_message' => $message,
            'backup_at' => $status === BackupStatus::Pending ? $device->backup_at : now(),
            'backup_commit' => $commit ?? ($status === BackupStatus::Pending ? $device->backup_commit : null),
        ])->save();
    }

    /** @param array<string,mixed> $result */
    private function messageFrom(array $result): ?string
    {
        $message = $result['message'] ?? null;

        return is_string($message) && $message !== '' ? mb_substr($message, 0, 250) : null;
    }

    /** Keep a stored error short (RustedClient already keeps secrets out of its messages). */
    private function cleanError(string $message): string
    {
        $message = trim($message);

        return $message === '' ? 'Backup failed.' : mb_substr($message, 0, 250);
    }
}
