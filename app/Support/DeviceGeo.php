<?php

namespace App\Support;

use App\Models\Device;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Effective geo coordinates for devices (GitHub #21). Precedence: a device with its own lat/lng
 * keeps it; one without takes its site's coordinates; one with neither inherits from the nearest
 * ancestor up the `parent_device_id` (uplink) chain, where each ancestor is itself read as
 * own-pin-else-site - so a CPE behind a tower AP shows at the tower without geocoding thousands
 * of endpoints, whether the tower was pinned by hand or placed via its site. Resolved in-memory
 * over the already-loaded device set (no per-device queries), so it holds up at large fleet sizes.
 *
 * Sets transient `geo_latitude` / `geo_longitude` / `geo_inherited` attributes that DeviceResource
 * reads; `geo_inherited` is false only when the device's own pin was used.
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

        // One query for every site the set references, so resolve() never lazy-loads per device.
        (new EloquentCollection(array_values($byId)))->loadMissing('site');

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

        if (($own = self::siteCoordinates($device)) !== null) {
            return [$own[0], $own[1], true];
        }

        // Walk the uplink chain to the first ancestor that resolves (own pin or site).
        // Cycle-guarded, and bounded to ancestors present in the set (a parent off this
        // page just ends the walk).
        $seen = [];
        $cursor = $device->parent_device_id;
        while ($cursor !== null && ! isset($seen[$cursor]) && isset($byId[$cursor])) {
            $seen[$cursor] = true;
            $ancestor = $byId[$cursor];
            if ($ancestor->latitude !== null && $ancestor->longitude !== null) {
                return [(float) $ancestor->latitude, (float) $ancestor->longitude, true];
            }
            if (($site = self::siteCoordinates($ancestor)) !== null) {
                return [$site[0], $site[1], true];
            }
            $cursor = $ancestor->parent_device_id;
        }

        return [null, null, false];
    }

    /** @return array{0: float, 1: float}|null The device's site's coordinates, when placed. */
    private static function siteCoordinates(Device $device): ?array
    {
        $site = $device->relationLoaded('site') ? $device->site : null;

        return $site?->isPlaced() ? [(float) $site->latitude, (float) $site->longitude] : null;
    }
}
