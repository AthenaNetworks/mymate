<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSensorRequest;
use App\Http\Resources\SensorResource;
use App\Models\Device;
use App\Models\Sensor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/** Custom SNMP sensor CRUD, plus the current readings for a given device. */
class SensorController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return SensorResource::collection(Sensor::orderBy('name')->get());
    }

    public function store(StoreSensorRequest $request): JsonResponse
    {
        $sensor = Sensor::create($request->validated());

        return (new SensorResource($sensor))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(StoreSensorRequest $request, Sensor $sensor): SensorResource
    {
        $sensor->update($request->validated());

        return new SensorResource($sensor);
    }

    public function destroy(Sensor $sensor): Response
    {
        $sensor->delete();

        return response()->noContent();
    }

    /** Current value of each enabled sensor that has a reading for this device. */
    public function forDevice(Device $device): JsonResponse
    {
        $rows = DB::table('sensor_readings as r')
            ->join('sensors as s', 's.id', '=', 'r.sensor_id')
            ->where('r.device_id', $device->id)
            ->where('s.enabled', true)
            ->orderBy('s.name')
            ->get(['s.id as sensor_id', 's.name', 's.unit', 'r.value', 'r.read_at']);

        return response()->json(['data' => $rows]);
    }
}
