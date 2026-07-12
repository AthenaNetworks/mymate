<?php

namespace App\Services\Polling;

use App\Models\Device;

/**
 * One interface behind SNMP and RouterOS, selected per device by
 * `poll_method`. Both implementations emit the same `InterfaceUtilUpdated`
 * event downstream, so the frontend stays driver-agnostic.
 */
interface ThroughputDriver
{
    /**
     * Enumerate the device's interfaces (name + capacity).
     *
     * @return list<array{if_index: int, name: string, speed_mbps: int|null}>
     */
    public function discover(Device $device): array;

    /**
     * Sample each interface's traffic, keyed by ifIndex. Each InterfaceSample is
     * either octet counters (SNMP -> delta downstream) or direct bits/sec
     * (RouterOS monitor-traffic) - see InterfaceSample.
     *
     * @return array<int, InterfaceSample>
     */
    public function sample(Device $device): array;
}
