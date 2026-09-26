<?php

namespace App\Actions\Devices;

use App\Models\Device;

/**
 * Hand a hand-placed device back to its SNMP / RouterOS location (GitHub #22). Once someone drags
 * a pin or types coordinates the device is flagged `manual` and the captured location stops
 * moving it. That's the right default, but it left no way back short of clearing the coords.
 *
 * When we already know what the location advertises (CaptureDeviceFacts keeps it in
 * snmp_latitude/longitude) the device moves there now. When we don't, the manual flag is dropped
 * and the pin stays where it is until the next capture places it.
 */
class UseSnmpLocation
{
    /** @return bool true when the device moved now, false when it waits on the next capture */
    public function __invoke(Device $device): bool
    {
        if ($device->snmp_latitude !== null && $device->snmp_longitude !== null) {
            $device->update([
                'latitude' => $device->snmp_latitude,
                'longitude' => $device->snmp_longitude,
                'geo_source' => 'snmp',
            ]);

            return true;
        }

        if ($device->geo_source === 'manual') {
            $device->update(['geo_source' => null]);
        }

        return false;
    }
}
