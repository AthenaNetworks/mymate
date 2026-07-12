<?php

namespace App\Services\Upgrade;

use App\Models\Device;

/**
 * Waits for a device to return to service after an upgrade reboot - so ordered
 * bulk upgrades only move up to the parent once the child is back. Behind an
 * interface so the orchestrator stays fakeable (no real sleeping in tests).
 */
interface DeviceRebootWaiter
{
    /** True if the device came back `up` within the timeout; false if it gave up. */
    public function awaitRecovery(Device $device): bool;
}
