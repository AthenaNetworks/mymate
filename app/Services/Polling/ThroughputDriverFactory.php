<?php

namespace App\Services\Polling;

use App\Enums\PollMethod;
use App\Models\Device;

/**
 * Picks the throughput driver for a device by its `poll_method`. Both drivers
 * implement ThroughputDriver and feed the same downstream, so callers stay
 * driver-agnostic.
 */
class ThroughputDriverFactory
{
    public function __construct(
        private SnmpThroughputDriver $snmp,
        private RouterOsThroughputDriver $routerOs,
    ) {}

    public function for(Device $device): ThroughputDriver
    {
        return match ($device->poll_method) {
            PollMethod::Snmp => $this->snmp,
            PollMethod::RouterOs => $this->routerOs,
            // Ping-only devices have no throughput source. The poll +
            // discovery loops already exclude them, so this is a defensive guard for
            // any direct call path (e.g. on-demand "Discover now") - a clear,
            // credential-free error rather than an UnhandledMatchError.
            PollMethod::None => throw new \InvalidArgumentException(
                "Device {$device->id} is ping-only (poll_method=none) and has no throughput driver.",
            ),
        };
    }
}
