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
    /**
     * @param  iterable<Device>  $devices
     * @param  bool  $loadAncestors  Fetch uplink ancestors missing from the set (a page of the
     *                               list, a single device) so inheritance still reaches them.
     */
    public static function apply(iterable $devices, bool $loadAncestors = false): void
    {
        $byId = [];
        foreach ($devices as $device) {
            $byId[$device->id] = $device;
        }

        $lookup = $loadAncestors ? self::withAncestors($byId) : $byId;

        // One query for every site the set references, so resolve() never lazy-loads per device.
        (new EloquentCollection(array_values($lookup)))->loadMissing('site');

        $nodes = [];
        $sites = [];
        foreach ($lookup as $device) {
            $nodes[$device->id] = [$device->latitude, $device->longitude, $device->site_id, $device->parent_device_id];
            $site = $device->relationLoaded('site') ? $device->site : null;
            if ($site?->isPlaced()) {
                $sites[$site->id] = [(float) $site->latitude, (float) $site->longitude];
            }
        }

        foreach ($byId as $device) {
            [$lat, $lng, $inherited] = self::resolveNode($device->id, $nodes, $sites);
            $device->geo_latitude = $lat;
            $device->geo_longitude = $lng;
            $device->geo_inherited = $inherited;
        }
    }

    /**
     * The same resolution over plain rows, for callers that read the whole fleet and can't afford
     * an Eloquent model per device (the geo feed at 25k devices, GitHub #22).
     *
     * @param  array<int, array{0: mixed, 1: mixed, 2: int|null, 3: int|null}>  $nodes  id => [lat, lng, site_id, parent_id]
     * @param  array<int, array{0: float, 1: float}>  $sites  placed sites only: id => [lat, lng]
     * @return array<int, array{0: float|null, 1: float|null, 2: bool}> id => [lat, lng, inherited]
     */
    public static function resolveAll(array $nodes, array $sites): array
    {
        $out = [];
        foreach ($nodes as $id => $_) {
            $out[$id] = self::resolveNode($id, $nodes, $sites);
        }

        return $out;
    }

    /**
     * Add the uplink ancestors the set is missing, one level per query and only the columns
     * resolve() reads. Depth-capped as a backstop; the seen check already stops a loop.
     *
     * @param  array<int, Device>  $byId
     * @return array<int, Device>
     */
    private static function withAncestors(array $byId): array
    {
        $lookup = $byId;
        $frontier = $byId;
        for ($depth = 0; $depth < 32 && $frontier !== []; $depth++) {
            $want = [];
            foreach ($frontier as $device) {
                $parentId = $device->parent_device_id;
                if ($parentId !== null && ! isset($lookup[$parentId])) {
                    $want[$parentId] = true;
                }
            }
            if ($want === []) {
                break;
            }

            $frontier = [];
            $rows = Device::query()->whereIn('id', array_keys($want))
                ->get(['id', 'parent_device_id', 'latitude', 'longitude', 'site_id']);
            foreach ($rows as $row) {
                $lookup[$row->id] = $row;
                $frontier[$row->id] = $row;
            }
        }

        return $lookup;
    }

    /**
     * Own pin, else site, else the first ancestor up the uplink chain that has either. Cycle
     * guarded, and bounded to ancestors present in the set (a parent off this page just ends it).
     *
     * @param  array<int, array{0: mixed, 1: mixed, 2: int|null, 3: int|null}>  $nodes
     * @param  array<int, array{0: float, 1: float}>  $sites
     * @return array{0: float|null, 1: float|null, 2: bool} [lat, lng, inherited]
     */
    private static function resolveNode(int $id, array $nodes, array $sites): array
    {
        [$lat, $lng, $siteId, $parentId] = $nodes[$id];
        if ($lat !== null && $lng !== null) {
            return [(float) $lat, (float) $lng, false];
        }
        if ($siteId !== null && isset($sites[$siteId])) {
            return [$sites[$siteId][0], $sites[$siteId][1], true];
        }

        $seen = [];
        $cursor = $parentId;
        while ($cursor !== null && ! isset($seen[$cursor]) && isset($nodes[$cursor])) {
            $seen[$cursor] = true;
            [$aLat, $aLng, $aSite, $aParent] = $nodes[$cursor];
            if ($aLat !== null && $aLng !== null) {
                return [(float) $aLat, (float) $aLng, true];
            }
            if ($aSite !== null && isset($sites[$aSite])) {
                return [$sites[$aSite][0], $sites[$aSite][1], true];
            }
            $cursor = $aParent;
        }

        return [null, null, false];
    }
}
