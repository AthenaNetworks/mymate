<?php

namespace App\Http\Controllers\Api;

use App\Actions\Devices\CreateDevice;
use App\Actions\Devices\DeleteDevice;
use App\Actions\Devices\UpdateDevice;
use App\Actions\Devices\UpdateDevicePosition;
use App\Actions\Devices\UpgradePreflight;
use App\Enums\UpgradeStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Device\StoreDeviceRequest;
use App\Http\Requests\Device\UpdateDevicePositionRequest;
use App\Http\Requests\Device\UpdateDeviceRequest;
use App\Http\Requests\Device\UpgradeDevicesRequest;
use App\Http\Resources\DeviceResource;
use App\Jobs\BulkUpgradeJob;
use App\Jobs\UpgradeDeviceJob;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class DeviceController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        // `site` is eager-loaded so DeviceGeo can read site coordinates without an N+1
        // across the whole fleet.
        $devices = Device::with(['parent', 'site'])->orderBy('name')->get();
        // Resolve effective geo coordinates (own, else the site's, else inherited from the uplink
        // parent) in-memory for the whole set, so the geo map can place CPE that have no
        // coordinates of their own.
        \App\Support\DeviceGeo::apply($devices);

        return DeviceResource::collection($devices);
    }

    public function store(StoreDeviceRequest $request, CreateDevice $createDevice): JsonResponse
    {
        $device = $createDevice($request->validated());

        return (new DeviceResource($device->loadMissing('parent')))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Device $device): DeviceResource
    {
        $device->loadMissing('parent', 'site');
        // Single-device responses can still resolve own-or-site coordinates; only uplink
        // inheritance needs the full set and stays a list-endpoint concern.
        \App\Support\DeviceGeo::apply([$device]);

        return new DeviceResource($device);
    }

    public function update(UpdateDeviceRequest $request, Device $device, UpdateDevice $updateDevice): DeviceResource
    {
        $device = $updateDevice($device, $request->validated())->loadMissing('parent', 'site');
        \App\Support\DeviceGeo::apply([$device]);

        return new DeviceResource($device);
    }

    public function updatePosition(UpdateDevicePositionRequest $request, Device $device, UpdateDevicePosition $updatePosition): DeviceResource
    {
        $data = $request->validated();

        return new DeviceResource($updatePosition($device, (float) $data['map_x'], (float) $data['map_y']));
    }

    public function destroy(Device $device, DeleteDevice $deleteDevice): Response
    {
        $deleteDevice($device);

        return response()->noContent();
    }

    /**
     * Dry-run the dependency checks: return the downstream-first
     * order and, per device, whether it would upgrade or be skipped (and why) -
     * without touching anything. The UI shows this before the operator confirms.
     */
    public function upgradePreflight(UpgradeDevicesRequest $request, UpgradePreflight $preflight): JsonResponse
    {
        $data = $request->validated();
        $ids = array_map('intval', $data['device_ids']);

        return response()->json($preflight($ids, (bool) ($data['preserve_order'] ?? false), $data['version'] ?? null));
    }

    /**
     * Queue a firmware upgrade for the given devices. `ordered` runs one
     * BulkUpgradeJob that walks them downstream-first (waiting for each to recover
     * before its parent); otherwise one isolated job per device, in parallel.
     */
    public function upgrade(UpgradeDevicesRequest $request): JsonResponse
    {
        $data = $request->validated();
        $ids = array_map('intval', $data['device_ids']);

        // Mark queued up front so the UI shows a spinner immediately (before a worker picks it up).
        Device::whereIn('id', $ids)->update([
            'upgrade_status' => UpgradeStatus::Queued,
            'upgrade_message' => 'Queued for upgrade...',
            'upgrade_at' => now(),
        ]);

        $version = $data['version'] ?? null;
        $source = $data['source'] ?? 'mikrotik';

        if ($data['ordered'] ?? false) {
            BulkUpgradeJob::dispatch($ids, (bool) ($data['explicit_order'] ?? false), $version, $source);
        } else {
            foreach ($ids as $id) {
                UpgradeDeviceJob::dispatch($id, $version, $source);
            }
        }

        return response()->json([
            'queued' => count($ids),
            'ordered' => (bool) ($data['ordered'] ?? false),
        ], Response::HTTP_ACCEPTED);
    }
}
