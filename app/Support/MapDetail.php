<?php

namespace App\Support;

use App\Http\Resources\MapLinkResource;
use App\Models\Device;
use App\Models\DeviceMapPosition;
use App\Models\Link;
use App\Models\Map;
use App\Models\MapLinkPosition;

/**
 * Builds a map's render payload: device placements, inter-map link portals, child maps and the
 * manual/aggregated links between them, plus free-text notes. Shared by the authenticated
 * MapController@show and the public wallboard endpoint (GitHub #15) so the two never drift.
 *
 * Nothing here is sensitive - it is ids, positions, device/map names and live throughput, which
 * is exactly what the canvas draws. No credentials, no management addresses.
 */
class MapDetail
{
    /** @return array<string, mixed> */
    public static function build(Map $map): array
    {
        $positions = $map->positions()->get(['device_id', 'x', 'y']);
        $memberIds = $positions->pluck('device_id')->all();
        $memberSet = array_flip($memberIds);

        // Eager-load each end's interface (util/bps/speed) so a portal can show the
        // link's live throughput, not just the destination map name.
        $links = Link::where(function ($q) use ($memberIds): void {
            $q->whereIn('a_device_id', $memberIds)->orWhereIn('b_device_id', $memberIds);
        })->with(['aInterface', 'bInterface'])->get();

        // Saved portal positions for this map's inter-map links (operator-dragged).
        $portalPos = MapLinkPosition::where('map_id', $map->id)->get()->keyBy('link_id');

        $interMap = [];
        foreach ($links as $link) {
            $aIn = isset($memberSet[$link->a_device_id]);
            $bIn = isset($memberSet[$link->b_device_id]);
            if ($aIn === $bIn) {
                continue; // both ends on this map (intra - the canvas draws it) or neither
            }
            $remoteId = $aIn ? $link->b_device_id : $link->a_device_id;
            $remote = DeviceMapPosition::where('device_id', $remoteId)
                ->where('map_id', '!=', $map->id)->with('map:id,name')->first();
            $pos = $portalPos->get($link->id);

            // Busiest throughput (bps) + util% across both ends - the link's load.
            $ifaces = array_filter([$link->aInterface, $link->bInterface]);
            $bpsList = [];
            $utilList = [];
            foreach ($ifaces as $if) {
                foreach ([$if->bps_in, $if->bps_out] as $b) {
                    if ($b !== null) {
                        $bpsList[] = (int) $b;
                    }
                }
                foreach ([$if->util_in, $if->util_out] as $u) {
                    if ($u !== null) {
                        $utilList[] = (float) $u;
                    }
                }
            }

            $interMap[] = [
                'id' => $link->id,
                'local_device_id' => $aIn ? $link->a_device_id : $link->b_device_id,
                'remote_device_id' => $remoteId,
                'remote_device_name' => Device::find($remoteId)?->name,
                'remote_map_id' => $remote?->map_id,
                'remote_map_name' => $remote?->map?->name,
                'bps' => $bpsList === [] ? null : max($bpsList),
                'util' => $utilList === [] ? null : round(max($utilList), 1),
                'portal_x' => $pos?->x,
                'portal_y' => $pos?->y,
            ];
        }

        // Child maps placed as nodes on this canvas (GitHub #9 overview maps), and the manual
        // device-less links drawn between them.
        $childMaps = $map->children()->withCount('positions')->get()
            ->map(fn (Map $c) => [
                'id' => $c->id, 'name' => $c->name,
                'node_x' => $c->node_x, 'node_y' => $c->node_y,
                'device_count' => $c->positions_count,
            ])->all();
        $mapLinks = MapLinkResource::collection($map->mapLinks()->get())->resolve();
        $mapNotes = $map->mapNotes()->get(['id', 'map_id', 'text', 'x', 'y', 'color', 'background', 'size'])->all();

        return [
            'id' => $map->id,
            'name' => $map->name,
            'parent_map_id' => $map->parent_map_id,
            'leaflet_enabled' => (bool) $map->leaflet_enabled,
            'positions' => $positions->map(fn ($p) => [
                'device_id' => $p->device_id, 'x' => $p->x, 'y' => $p->y,
            ])->all(),
            'inter_map_links' => $interMap,
            'child_maps' => $childMaps,
            'child_device_links' => self::childDeviceLinks($map),
            'map_links' => $mapLinks,
            'map_notes' => $mapNotes,
        ];
    }

    /**
     * Real device links that cross between the child maps placed on this canvas (GitHub #9),
     * aggregated to one entry per child pair with a count.
     *
     * @return list<array{a_map_id:int, b_map_id:int, count:int}>
     */
    private static function childDeviceLinks(Map $map): array
    {
        $childIds = $map->children()->pluck('id');
        if ($childIds->count() < 2) {
            return []; // need at least two sub-maps to have a link between them
        }

        // device_id -> the child maps it sits on (a device can be on several).
        $childOfDevice = [];
        foreach (DeviceMapPosition::whereIn('map_id', $childIds)->get(['device_id', 'map_id']) as $pos) {
            $childOfDevice[$pos->device_id][] = $pos->map_id;
        }
        $deviceIds = array_keys($childOfDevice);
        if ($deviceIds === []) {
            return [];
        }

        $counts = []; // "min:max" child pair -> link count
        $links = Link::whereIn('a_device_id', $deviceIds)->whereIn('b_device_id', $deviceIds)->get(['a_device_id', 'b_device_id']);
        foreach ($links as $l) {
            foreach ($childOfDevice[$l->a_device_id] ?? [] as $ca) {
                foreach ($childOfDevice[$l->b_device_id] ?? [] as $cb) {
                    if ($ca === $cb) {
                        continue; // both ends on the same sub-map - drawn inside it, not here
                    }
                    $key = $ca < $cb ? "{$ca}:{$cb}" : "{$cb}:{$ca}";
                    $counts[$key] = ($counts[$key] ?? 0) + 1;
                }
            }
        }

        $out = [];
        foreach ($counts as $key => $count) {
            [$a, $b] = explode(':', $key);
            $out[] = ['a_map_id' => (int) $a, 'b_map_id' => (int) $b, 'count' => $count];
        }

        return $out;
    }
}
