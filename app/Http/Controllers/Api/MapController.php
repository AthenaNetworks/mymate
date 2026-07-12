<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Map\StoreMapRequest;
use App\Http\Requests\Map\UpdateMapRequest;
use App\Http\Resources\MapResource;
use App\Models\Device;
use App\Models\DeviceMapPosition;
use App\Models\Link;
use App\Models\Map;
use App\Models\MapLinkPosition;
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

        return response()->json([
            'data' => [
                'id' => $map->id,
                'name' => $map->name,
                'parent_map_id' => $map->parent_map_id,
                'positions' => $positions->map(fn ($p) => [
                    'device_id' => $p->device_id, 'x' => $p->x, 'y' => $p->y,
                ])->all(),
                'inter_map_links' => $interMap,
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
}
