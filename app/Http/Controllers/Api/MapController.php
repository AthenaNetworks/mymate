<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Map\StoreMapRequest;
use App\Http\Requests\Map\UpdateMapRequest;
use App\Http\Resources\MapLinkResource;
use App\Http\Resources\MapResource;
use App\Models\Device;
use App\Models\DeviceMapPosition;
use App\Models\Link;
use App\Models\Map;
use App\Models\MapLink;
use App\Models\MapLinkPosition;
use Illuminate\Validation\Rule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Multiple maps. `index` is the (flat) map tree; `show` returns one
 * map's device placements + its inter-map links (a link whose other end is on a
 * different map) so the canvas can draw a connector that navigates there.
 */
class MapController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return MapResource::collection(
            Map::withCount('positions')->orderBy('position')->orderBy('name')->get(),
        );
    }

    public function store(StoreMapRequest $request): JsonResponse
    {
        $map = Map::create($request->validated());

        return (new MapResource($map->loadCount('positions')))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateMapRequest $request, Map $map): MapResource
    {
        $map->update($request->validated());

        return new MapResource($map->loadCount('positions'));
    }

    public function destroy(Map $map): Response|JsonResponse
    {
        if ($map->is_default) {
            return response()->json(['message' => 'The default map cannot be deleted.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $map->delete(); // cascade drops its device_map_positions, never the devices

        return response()->noContent();
    }

    /** One map's device placements + inter-map links (for the canvas). */
    public function show(Map $map): JsonResponse
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

        return response()->json([
            'data' => [
                'id' => $map->id,
                'name' => $map->name,
                'parent_map_id' => $map->parent_map_id,
                'positions' => $positions->map(fn ($p) => [
                    'device_id' => $p->device_id, 'x' => $p->x, 'y' => $p->y,
                ])->all(),
                'inter_map_links' => $interMap,
                'child_maps' => $childMaps,
                'map_links' => $mapLinks,
            ],
        ]);
    }

    /** Save a device's position on this map. */
    public function savePosition(Request $request, Map $map, Device $device): JsonResponse
    {
        $data = $request->validate(['x' => ['required', 'numeric'], 'y' => ['required', 'numeric']]);

        DeviceMapPosition::updateOrCreate(
            ['device_id' => $device->id, 'map_id' => $map->id],
            ['x' => $data['x'], 'y' => $data['y']],
        );

        return response()->json(['ok' => true]);
    }

    /** Persist where an inter-map link's portal node sits on this map (drag to move). */
    public function saveLinkPosition(Request $request, Map $map, Link $link): JsonResponse
    {
        $data = $request->validate(['x' => ['required', 'numeric'], 'y' => ['required', 'numeric']]);

        MapLinkPosition::updateOrCreate(
            ['map_id' => $map->id, 'link_id' => $link->id],
            ['x' => $data['x'], 'y' => $data['y']],
        );

        return response()->json(['ok' => true]);
    }

    /** Place a device on this map. */
    public function addDevice(Request $request, Map $map): JsonResponse
    {
        $data = $request->validate([
            'device_id' => ['required', 'integer', 'exists:devices,id'],
            'x' => ['nullable', 'numeric'],
            'y' => ['nullable', 'numeric'],
        ]);

        DeviceMapPosition::firstOrCreate(
            ['device_id' => $data['device_id'], 'map_id' => $map->id],
            ['x' => $data['x'] ?? 0, 'y' => $data['y'] ?? 0],
        );

        return response()->json(['ok' => true], Response::HTTP_CREATED);
    }

    /** Remove a device from this map (the device + its links are untouched). */
    public function removeDevice(Map $map, Device $device): Response
    {
        DeviceMapPosition::where('map_id', $map->id)->where('device_id', $device->id)->delete();

        return response()->noContent();
    }

    // --- Child-map nodes + manual links (GitHub #9) -----------------------

    /** Place an existing map as a node on this map (nesting it as a child) at a position. */
    public function addChildMap(Request $request, Map $map): JsonResponse
    {
        $data = $request->validate([
            'child_map_id' => [
                'required', 'integer', Rule::exists('maps', 'id'),
                Rule::notIn([$map->id]), // a map can't be a node on itself
            ],
            'x' => ['nullable', 'numeric'],
            'y' => ['nullable', 'numeric'],
        ]);

        $child = Map::findOrFail($data['child_map_id']);
        // Guard against a cycle: the target can't be an ancestor of this map.
        abort_if($this->isAncestor($child, $map), Response::HTTP_UNPROCESSABLE_ENTITY, 'That would nest a map inside itself.');

        $child->update([
            'parent_map_id' => $map->id,
            'node_x' => $data['x'] ?? 0,
            'node_y' => $data['y'] ?? 0,
        ]);

        return response()->json(['ok' => true], Response::HTTP_CREATED);
    }

    /** Move a child-map node on this canvas. */
    public function saveChildPosition(Request $request, Map $map, Map $child): JsonResponse
    {
        abort_unless($child->parent_map_id === $map->id, Response::HTTP_NOT_FOUND);
        $data = $request->validate(['x' => ['required', 'numeric'], 'y' => ['required', 'numeric']]);
        $child->update(['node_x' => $data['x'], 'node_y' => $data['y']]);

        return response()->json(['ok' => true]);
    }

    /** Remove a child-map node from this canvas (detach it; the map itself is untouched). */
    public function removeChildMap(Map $map, Map $child): Response
    {
        if ($child->parent_map_id === $map->id) {
            // Drop any manual links on this canvas that touch the node, so no edge is left
            // pointing at a node that's no longer here. (Grouped so the map_id scope holds.)
            $map->mapLinks()
                ->where(fn ($q) => $q->where('a_map_id', $child->id)->orWhere('b_map_id', $child->id))
                ->delete();
            $child->update(['parent_map_id' => null, 'node_x' => null, 'node_y' => null]);
        }

        return response()->noContent();
    }

    /** Draw a manual link between two child-map nodes on this canvas. */
    public function storeMapLink(Request $request, Map $map): JsonResponse
    {
        $data = $request->validate([
            'a_map_id' => ['required', 'integer', Rule::exists('maps', 'id')->where('parent_map_id', $map->id)],
            'b_map_id' => ['required', 'integer', 'different:a_map_id', Rule::exists('maps', 'id')->where('parent_map_id', $map->id)],
            'a_handle' => ['sometimes', 'nullable', 'string', Rule::in(MapLink::HANDLES)],
            'b_handle' => ['sometimes', 'nullable', 'string', Rule::in(MapLink::HANDLES)],
            'media_type' => ['sometimes', 'nullable', Rule::in(MapLink::MEDIA_TYPES)],
            'label' => ['sometimes', 'nullable', 'string', 'max:80'],
        ]);

        $link = $map->mapLinks()->create($data);

        return (new MapLinkResource($link))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /** Update a manual link's medium / label. */
    public function updateMapLink(Request $request, Map $map, MapLink $mapLink): MapLinkResource
    {
        abort_unless($mapLink->map_id === $map->id, Response::HTTP_NOT_FOUND);
        $data = $request->validate([
            'media_type' => ['sometimes', 'nullable', Rule::in(MapLink::MEDIA_TYPES)],
            'label' => ['sometimes', 'nullable', 'string', 'max:80'],
        ]);
        $mapLink->update($data);

        return new MapLinkResource($mapLink);
    }

    /** Delete a manual link. */
    public function destroyMapLink(Map $map, MapLink $mapLink): Response
    {
        abort_unless($mapLink->map_id === $map->id, Response::HTTP_NOT_FOUND);
        $mapLink->delete();

        return response()->noContent();
    }

    /** True when $candidate is $map itself or an ancestor of it (cycle guard for nesting). */
    private function isAncestor(Map $candidate, Map $map): bool
    {
        for ($m = $map; $m !== null; $m = $m->parent) {
            if ($m->id === $candidate->id) {
                return true;
            }
        }

        return false;
    }
}
