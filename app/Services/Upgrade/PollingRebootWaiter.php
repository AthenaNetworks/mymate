<?php

namespace App\Services\Upgrade;

use App\Enums\DeviceStatus;
use App\Models\Device;
use App\Support\EngineLog;

/**
 * Polls `devices.status` (kept fresh by the fping loop) until the device returns
 * `up` after its reboot, bounded by `mymate.upgrade.reboot_wait_s`. Runs on the
 * isolated `upgrade` queue (timeout 900) so the bounded sleeping never blocks
 * polling/discovery.
 *
 * "Recovered" = observed `down` (the reboot took effect) and then `up` again. If
 * the device never appears to drop, a single `up` after the grace also counts so a
 * fast reboot isn't waited out forever.
 */
class PollingRebootWaiter implements DeviceRebootWaiter
{
    public function awaitRecovery(Device $device): bool
    {
        $timeout = max(1, (int) config('mymate.upgrade.reboot_wait_s', 300));
        $interval = max(1, (int) config('mymate.upgrade.poll_interval_s', 10));
        $deadline = microtime(true) + $timeout;

        $sawDown = false;

        while (microtime(true) < $deadline) {
            $this->sleep($interval);

            $status = Device::find($device->id)?->status;

            if ($status === DeviceStatus::Down) {
                $sawDown = true;
            } elseif ($status === DeviceStatus::Up && $sawDown) {
                return true; // went down then came back - fully recovered
            }
        }

        EngineLog::error('upgrade: device did not recover before timeout', [
            'device_id' => $device->id,
            'device' => $device->name,
            'timeout_s' => $timeout,
        ]);

        return false;
    }

    /** Isolated so tests can override without real time passing. */
    protected function sleep(int $seconds): void
    {
        sleep($seconds);
    }
}
