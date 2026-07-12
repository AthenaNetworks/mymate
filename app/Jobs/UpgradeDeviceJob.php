<?php

namespace App\Jobs;

use App\Actions\Devices\UpgradeDevice;
use App\Models\Device;
use App\Support\EngineLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Thin queue envelope: upgrade one device, on the isolated `upgrade` queue so a
 * slow/rebooting device never delays polling or discovery. `tries=1` (a half-applied
 * upgrade must not be retried blindly) + a per-device overlap lock (no concurrent
 * upgrades of the same device). One job per device - bulk = many of these.
 */
class UpgradeDeviceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $deviceId)
    {
        $this->onQueue('upgrade');
    }

    public function handle(UpgradeDevice $upgrade): void
    {
        $device = Device::find($this->deviceId);
        if ($device !== null) {
            $upgrade($device);
        }
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("upgrade-device-{$this->deviceId}"))->dontRelease()->expireAfter(900)];
    }

    public function failed(Throwable $e): void
    {
        EngineLog::error('upgrade: job failed', [
            'device_id' => $this->deviceId,
            'exception' => $e::class,
            'error' => $e->getMessage(),
        ]);
    }
}
