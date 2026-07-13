<?php

namespace App\Services\Polling;

use App\Models\Device;

/**
 * Reads a device's resource metrics (cpu/mem/temp) for one tick. Implementations
 * fail fast on an unreachable/filtered host (the orchestrator isolates per device).
 */
interface DeviceMetricsDriver
{
    public function sample(Device $device): DeviceMetrics;
}
