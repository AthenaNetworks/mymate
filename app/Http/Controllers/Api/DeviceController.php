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
        return DeviceResource::collection(Device::with('parent')->orderBy('name')->get());
    }

    public function store(StoreDeviceRequest $request, CreateDevice $createDevice): JsonResponse
    {
        $device = $createDevice($request->validated());

        return (new DeviceResource($device->loadMissing('parent')))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Device $device): DeviceResource
    {
        return new DeviceResource($device->loadMissing('parent'));
    }

    public function update(UpdateDeviceRequest $request, Device $device, UpdateDevice $updateDevice): DeviceResource
    {
        return new DeviceResource($updateDevice($device, $request->validated())->loadMissing('parent'));
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

        return response()->json($preflight($ids, (bool) ($data['preserve_order'] ?? false)));
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

        if ($data['ordered'] ?? false) {
            BulkUpgradeJob::dispatch($ids, (bool) ($data['explicit_order'] ?? false));
        } else {
            foreach ($ids as $id) {
                UpgradeDeviceJob::dispatch($id);
            }
        }

        return response()->json([
            'queued' => count($ids),
            'ordered' => (bool) ($data['ordered'] ?? false),
        ], Response::HTTP_ACCEPTED);
    }
}
