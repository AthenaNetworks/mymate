<?php

namespace App\Services\Polling;

use App\Models\Device;

/**
 * Resolves the SNMP OID profile to read a device's cpu/mem/temp with. Matched by a
 * case-insensitive substring of the device's detected vendor (e.g. "MikroTik", "Cisco")
 * against the profile keys in config('mymate.device_metrics.profiles'); falls back to
 * the 'default' host-resources-MIB profile when nothing matches.
 */
class DeviceMetricProfiles
{
    /** @return array<string, mixed> */
    public function for(Device $device): array
    {
        /** @var array<string, array<string, mixed>> $profiles */
        $profiles = config('mymate.device_metrics.profiles', []);
        $vendor = strtolower(trim((string) $device->vendor));

        if ($vendor !== '') {
            foreach ($profiles as $key => $profile) {
                if ($key !== 'default' && str_contains($vendor, strtolower($key))) {
                    return $profile;
                }
            }
        }

        return $profiles['default'] ?? [];
    }
}
