<?php

namespace App\Support;

use App\Models\Device;

/**
 * Effective geo coordinates for devices (GitHub #21). A device with its own lat/lng keeps it; one
 * without inherits the nearest ancestor's up the `parent_device_id` (uplink) chain - so a CPE
 * behind a tower AP shows at the tower without geocoding thousands of endpoints. Resolved in-memory
 * over the already-loaded device set (no per-device queries), so it holds up at large fleet sizes.
 *
 * Sets transient `geo_latitude` / `geo_longitude` / `geo_inherited` attributes that DeviceResource
 * reads. This is the lightweight inheritance path; an explicit Site/location model could later be
 * another source in the same resolution without changing callers.
 */
class DeviceGeo
{
    /** @param  iterable<Device>  $devices */
    public static function apply(iterable $devices): void
    {
        $byId = [];
        foreach ($devices as $device) {
            $byId[$device->id] = $device;
        }

        foreach ($byId as $device) {
            [$lat, $lng, $inherited] = self::resolve($device, $byId);
            $device->geo_latitude = $lat;
            $device->geo_longitude = $lng;
            $device->geo_inherited = $inherited;
        }
    }

    /**
     * @param  array<int, Device>  $byId
     * @return array{0: float|null, 1: float|null, 2: bool} [lat, lng, inherited]
     */
    private static function resolve(Device $device, array $byId): array
    {
        if ($device->latitude !== null && $device->longitude !== null) {
            return [(float) $device->latitude, (float) $device->longitude, false];
        }

        // Walk the uplink chain to the first ancestor that has coordinates. Cycle-guarded, and
        // bounded to ancestors present in the set (a parent off this page just ends the walk).
        $seen = [];
        $cursor = $device->parent_device_id;
        while ($cursor !== null && ! isset($seen[$cursor]) && isset($byId[$cursor])) {
            $seen[$cursor] = true;
            $ancestor = $byId[$cursor];
            if ($ancestor->latitude !== null && $ancestor->longitude !== null) {
                return [(float) $ancestor->latitude, (float) $ancestor->longitude, true];
            }
            $cursor = $ancestor->parent_device_id;
        }

        return [null, null, false];
    }
}
