<?php

namespace App\Actions\Maps;

use App\Enums\DeviceType;
use App\Enums\PollMethod;
use App\Models\Device;
use App\Models\DeviceMapPosition;
use App\Models\Link;
use App\Models\Map;
use App\Models\MapLink;
use App\Models\NetworkInterface;
use Illuminate\Support\Facades\DB;

/**
 * Rebuild a map from an {@see ExportMap} payload (GitHub #11). Devices are matched to existing
 * ones by mgmt_ip and created (credential-less, so unmonitored until you add one) when missing -
 * so importing a layout onto an instance that already has the devices just restores the layout.
 * Everything runs in one transaction; a bad payload rolls back cleanly.
 */
class ImportMap
{
    /** @param  array<string, mixed>  $data */
    public function __invoke(array $data): Map
    {
        return DB::transaction(function () use ($data): Map {
            $map = Map::create(['name' => $this->uniqueName((string) ($data['map']['name'] ?? 'Imported map'))]);

            // Devices: match by mgmt_ip, else create. Placed on the new map at their saved x/y.
            $deviceByIp = [];
            foreach ($data['devices'] ?? [] as $d) {
                $ip = (string) ($d['mgmt_ip'] ?? '');
                if ($ip === '') {
                    continue;
                }
                $device = Device::firstOrCreate(
                    ['mgmt_ip' => $ip],
                    [
                        'name' => (string) ($d['name'] ?? $ip),
                        'device_type' => DeviceType::tryFrom((string) ($d['device_type'] ?? '')) ?? DeviceType::Unknown,
                        'poll_method' => PollMethod::tryFrom((string) ($d['poll_method'] ?? '')) ?? PollMethod::None,
                        'icon' => $d['icon'] ?? null,
                        'icon_color' => $d['icon_color'] ?? null,
                    ],
                );
                $deviceByIp[$ip] = $device;
                DeviceMapPosition::updateOrCreate(
                    ['device_id' => $device->id, 'map_id' => $map->id],
                    ['x' => (float) ($d['x'] ?? 0), 'y' => (float) ($d['y'] ?? 0)],
                );
            }

            // Links between resolved devices. An interface is matched by name on its device; a
            // missing one just means a ping-only end (interface_id null). Skip a duplicate.
            foreach ($data['links'] ?? [] as $l) {
                $a = $deviceByIp[(string) ($l['a_ip'] ?? '')] ?? null;
                $b = $deviceByIp[(string) ($l['b_ip'] ?? '')] ?? null;
                if ($a === null || $b === null || $a->id === $b->id) {
                    continue;
                }
                if ($this->linkExists($a->id, $b->id)) {
                    continue;
                }
                Link::create([
                    'a_device_id' => $a->id,
                    'a_interface_id' => $this->interfaceId($a->id, $l['a_if'] ?? null),
                    'b_device_id' => $b->id,
                    'b_interface_id' => $this->interfaceId($b->id, $l['b_if'] ?? null),
                    'media_type' => in_array($l['media_type'] ?? null, Link::MEDIA_TYPES, true) ? $l['media_type'] : null,
                    'bw_ab_mbps' => $l['bw_ab_mbps'] ?? null,
                    'bw_ba_mbps' => $l['bw_ba_mbps'] ?? null,
                    'a_handle' => $l['a_handle'] ?? null,
                    'b_handle' => $l['b_handle'] ?? null,
                ]);
            }

            foreach ($data['notes'] ?? [] as $n) {
                $map->mapNotes()->create([
                    'text' => (string) ($n['text'] ?? ''),
                    'x' => (float) ($n['x'] ?? 0), 'y' => (float) ($n['y'] ?? 0),
                    'color' => $n['color'] ?? null,
                ]);
            }

            // Child-map overview nodes (matched/created by name) + the manual links between them.
            $childByName = [];
            foreach ($data['child_maps'] ?? [] as $c) {
                $name = (string) ($c['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $child = Map::firstOrCreate(['name' => $name, 'parent_map_id' => $map->id]);
                $child->update(['node_x' => $c['node_x'] ?? 0, 'node_y' => $c['node_y'] ?? 0]);
                $childByName[$name] = $child;
            }
            foreach ($data['map_links'] ?? [] as $ml) {
                $a = $childByName[(string) ($ml['a_name'] ?? '')] ?? null;
                $b = $childByName[(string) ($ml['b_name'] ?? '')] ?? null;
                if ($a === null || $b === null || $a->id === $b->id) {
                    continue;
                }
                $map->mapLinks()->create([
                    'a_map_id' => $a->id, 'b_map_id' => $b->id,
                    'media_type' => in_array($ml['media_type'] ?? null, MapLink::MEDIA_TYPES, true) ? $ml['media_type'] : null,
                    'label' => $ml['label'] ?? null,
                    'a_handle' => $ml['a_handle'] ?? null,
                    'b_handle' => $ml['b_handle'] ?? null,
                ]);
            }

            return $map;
        });
    }

    /** A map name that doesn't collide - appends " (imported)" / a counter as needed. */
    private function uniqueName(string $name): string
    {
        $candidate = $name;
        if (! Map::where('name', $candidate)->exists()) {
            return $candidate;
        }
        $candidate = "{$name} (imported)";
        $n = 2;
        while (Map::where('name', $candidate)->exists()) {
            $candidate = "{$name} (imported {$n})";
            $n++;
        }

        return $candidate;
    }

    private function interfaceId(int $deviceId, mixed $name): ?int
    {
        if (! is_string($name) || $name === '') {
            return null;
        }

        return NetworkInterface::where('device_id', $deviceId)->where('name', $name)->value('id');
    }

    private function linkExists(int $a, int $b): bool
    {
        return Link::where(fn ($q) => $q->where('a_device_id', $a)->where('b_device_id', $b))
            ->orWhere(fn ($q) => $q->where('a_device_id', $b)->where('b_device_id', $a))
            ->exists();
    }
}
