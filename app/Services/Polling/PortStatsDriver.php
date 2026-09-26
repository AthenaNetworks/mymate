<?php

namespace App\Services\Polling;

use App\Models\Device;

/**
 * A throughput driver that reads the port counters (packets, errors, discards) as a separate,
 * slower read than the octets. SNMP does, because it's extra OIDs per port and errors don't need
 * a 12 second resolution. RouterOS doesn't need this, `/interface/print` already hands the
 * counters over with every tick (see InterfaceSample::withCounters).
 */
interface PortStatsDriver
{
    /**
     * Raw counters for the given (already discovered) ifIndexes. A port the device answers
     * nothing for is left out, and a counter it lacks is left out of that port's array.
     *
     * @param  list<int>  $ifIndexes
     * @return array<int, array<string, int>> ifIndex => [PortStats::RATES name => raw counter]
     */
    public function portCounters(Device $device, array $ifIndexes): array;
}
