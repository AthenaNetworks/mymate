<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSensorRequest;
use App\Http\Resources\SensorResource;
use App\Models\Device;
use App\Models\Sensor;
use App\Services\Snmp\SnmpCredential;
use App\Support\SensorReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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

    /**
     * Test an OID against a chosen device without saving the sensor: reads it exactly the way the
     * poller will ({@see SensorReader}), so the operator can confirm it returns a value (and see it)
     * before committing. Errors come back as a message rather than a 500 so the form can show them.
     */
    public function test(Request $request, SensorReader $reader): JsonResponse
    {
        $data = $request->validate([
            'device_id' => ['required', 'integer', 'exists:devices,id'],
            'oid' => ['required', 'string', 'max:255'],
            'mode' => ['sometimes', Rule::in(['get', 'walk'])],
            'agg' => ['sometimes', 'nullable', Rule::in(['sum', 'avg', 'max', 'min', 'count'])],
            'divisor' => ['sometimes', 'numeric'],
        ]);

        $device = Device::with('credential')->findOrFail($data['device_id']);
        $cred = SnmpCredential::fromCredential($device->credential);
        if (! $cred->isUsable()) {
            return response()->json(['data' => ['ok' => false, 'error' => 'That device has no usable SNMP credential to test with.']]);
        }

        try {
            $value = $reader->read(
                $device->mgmt_ip, $cred, $data['oid'],
                $data['mode'] ?? 'get', $data['agg'] ?? null, (float) ($data['divisor'] ?? 1),
            );
        } catch (\Throwable $e) {
            return response()->json(['data' => ['ok' => false, 'error' => 'SNMP read failed: '.$e->getMessage()]]);
        }

        if ($value === null) {
            return response()->json(['data' => ['ok' => false, 'error' => 'That OID returned no numeric value on this device.']]);
        }

        return response()->json(['data' => ['ok' => true, 'value' => $value]]);
    }

    public function update(StoreSensorRequest $request, Sensor $sensor): SensorResource
    {
        $sensor->update($request->validated());

        return new SensorResource($sensor);
    }

    /**
     * Current readings for every "show on device face" sensor, keyed by device, so the map can
     * label each card (GitHub #40). `sensor_readings` already only holds in-scope (sensor, device)
     * pairs, so a plain join is enough - no scope re-resolution.
     *
     * @return array<int, list<array{name:string,value:float,unit:?string}>> is the payload shape
     */
    public function faceReadings(): JsonResponse
    {
        $rows = DB::table('sensor_readings as sr')
            ->join('sensors as s', 's.id', '=', 'sr.sensor_id')
            ->where('s.enabled', true)
            ->where('s.on_face', true)
            ->whereNotNull('sr.value')
            ->orderBy('s.name')
            ->get(['sr.device_id', 's.name', 'sr.value', 's.unit']);

        $byDevice = [];
        foreach ($rows as $r) {
            $byDevice[(int) $r->device_id][] = ['name' => $r->name, 'value' => (float) $r->value, 'unit' => $r->unit];
        }

        return response()->json(['data' => $byDevice]);
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
