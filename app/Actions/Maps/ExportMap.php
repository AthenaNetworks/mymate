<?php

namespace App\Actions\Maps;

use App\Models\DeviceMapPosition;
use App\Models\Link;
use App\Models\Map;

/**
 * Serialise a map's layout to a portable, secret-free array (GitHub #11) - devices (by
 * identity, never credentials), their positions, the links between them, notes, and the
 * child-map overview structure. {@see ImportMap} rebuilds a map from this.
 */
class ExportMap
{
    /** @return array<string, mixed> */
    public function __invoke(Map $map): array
    {
        $positions = DeviceMapPosition::where('map_id', $map->id)->with('device')->get();
        $byDeviceId = $positions->keyBy('device_id');
        $memberIds = $positions->pluck('device_id')->all();

        $devices = $positions->map(fn (DeviceMapPosition $p) => [
            'name' => $p->device->name,
            'mgmt_ip' => $p->device->mgmt_ip,
            'device_type' => $p->device->device_type?->value,
            'poll_method' => $p->device->poll_method->value,
            'icon' => $p->device->icon,
            'icon_color' => $p->device->icon_color,
            'x' => $p->x,
            'y' => $p->y,
        ])->values()->all();

        // Only links whose BOTH ends are on this map - the intra-map wiring.
        $links = Link::with(['aInterface:id,name', 'bInterface:id,name', 'aDevice:id,mgmt_ip', 'bDevice:id,mgmt_ip'])
            ->whereIn('a_device_id', $memberIds)->whereIn('b_device_id', $memberIds)
            ->get()
            ->filter(fn (Link $l) => $byDeviceId->has($l->a_device_id) && $byDeviceId->has($l->b_device_id))
            ->map(fn (Link $l) => [
                'a_ip' => $l->aDevice?->mgmt_ip,
                'a_if' => $l->aInterface?->name,
                'b_ip' => $l->bDevice?->mgmt_ip,
                'b_if' => $l->bInterface?->name,
                'media_type' => $l->media_type,
                'bw_ab_mbps' => $l->bw_ab_mbps,
                'bw_ba_mbps' => $l->bw_ba_mbps,
                'a_handle' => $l->a_handle,
                'b_handle' => $l->b_handle,
            ])->values()->all();

        $notes = $map->mapNotes()->get(['text', 'x', 'y', 'color'])->all();

        $childMaps = $map->children()->get(['id', 'name', 'node_x', 'node_y'])
            ->map(fn (Map $c) => ['name' => $c->name, 'node_x' => $c->node_x, 'node_y' => $c->node_y])->all();

        $childNameById = $map->children()->pluck('name', 'id');
        $mapLinks = $map->mapLinks()->get()
            ->map(fn ($ml) => [
                'a_name' => $childNameById->get($ml->a_map_id),
                'b_name' => $childNameById->get($ml->b_map_id),
                'media_type' => $ml->media_type,
                'label' => $ml->label,
                'a_handle' => $ml->a_handle,
                'b_handle' => $ml->b_handle,
            ])->filter(fn ($ml) => $ml['a_name'] !== null && $ml['b_name'] !== null)->values()->all();

        return [
            'version' => 1,
            'map' => ['name' => $map->name],
            'devices' => $devices,
            'links' => $links,
            'notes' => $notes,
            'child_maps' => $childMaps,
            'map_links' => $mapLinks,
        ];
    }
}
