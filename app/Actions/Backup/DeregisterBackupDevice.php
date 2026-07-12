<?php

namespace App\Actions\Backup;

use App\Models\Device;
use App\Services\Backup\RustedClient;
use App\Support\EngineLog;

/**
 * Remove a device from the Rusted engine - called when an operator turns
 * backups off for a device. Best-effort: a failure here (Rusted down, already gone) must
 * never block the My Mate-side config change, so it's caught and logged, not thrown.
 */
class DeregisterBackupDevice
{
    public function __construct(private RustedClient $client) {}

    public function __invoke(Device $device): void
    {
        try {
            $this->client->deleteDevice(RegisterBackupDevice::rustedName($device));
        } catch (\Throwable $e) {
            EngineLog::warning('backup: deregister failed', [
                'device_id' => $device->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
