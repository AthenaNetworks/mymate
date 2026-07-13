<?php

namespace App\Services\Polling;

use App\Enums\PollMethod;
use App\Models\Device;

/**
 * Picks the metrics driver for a device by its poll_method - mirrors
 * ThroughputDriverFactory. Ping-only devices have no metrics source.
 */
class DeviceMetricsDriverFactory
{
    public function __construct(
        private SnmpDeviceMetricsDriver $snmp,
        private RouterOsDeviceMetricsDriver $routerOs,
    ) {}

    public function for(Device $device): DeviceMetricsDriver
    {
        return match ($device->poll_method) {
            PollMethod::Snmp => $this->snmp,
            PollMethod::RouterOs => $this->routerOs,
            PollMethod::None => throw new \InvalidArgumentException(
                "Device {$device->id} is ping-only (poll_method=none) and has no metrics driver.",
            ),
        };
    }
}
