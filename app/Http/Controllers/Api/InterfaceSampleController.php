<?php

namespace App\Http\Controllers\Api;

use App\Actions\History\GetDeviceMetricSamples;
use App\Actions\History\GetDeviceSamples;
use App\Actions\History\GetPingSamples;
use App\Actions\History\GetSensorSamples;
use App\Models\Sensor;
use App\Actions\History\GetInterfaceSamples;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\NetworkInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class InterfaceSampleController extends Controller
{
    /**
     * GET /api/interfaces/{interface}/samples?from&to
     * Returns a bucketed util/bps series for the chart. Defaults to the last
     * `history.default_window` seconds when from/to aren't given.
     */
    public function index(Request $request, NetworkInterface $interface, GetInterfaceSamples $get): JsonResponse
    {
        [$from, $to] = $this->window($request);

        return response()->json(['data' => $get($interface->id, $from, $to)]);
    }

    /**
     * GET /api/devices/{device}/samples?from&to
     * Total throughput for the whole device - bps summed across its interfaces per
     * bucket (util averaged). Backs the inspector's device-level chart.
     */
    public function device(Request $request, Device $device, GetDeviceSamples $get): JsonResponse
    {
        [$from, $to] = $this->window($request);

        return response()->json(['data' => $get($device->id, $from, $to)]);
    }

    /**
     * GET /api/devices/{device}/metric-samples?from&to
     * Bucketed cpu/mem/temp history for the device - backs the inspector's resource chart.
     */
    public function metrics(Request $request, Device $device, GetDeviceMetricSamples $get): JsonResponse
    {
        [$from, $to] = $this->window($request);

        return response()->json(['data' => $get($device->id, $from, $to)]);
    }

    /**
     * GET /api/devices/{device}/ping-samples?from&to
     * Bucketed latency / packet-loss / jitter series for the device's ping chart.
     */
    public function ping(Request $request, Device $device, GetPingSamples $get): JsonResponse
    {
        [$from, $to] = $this->window($request);

        return response()->json(['data' => $get($device->id, $from, $to)]);
    }

    /**
     * GET /api/devices/{device}/sensors/{sensor}/samples?from&to
     * Bucketed value series for one custom sensor on one device.
     */
    public function sensor(Request $request, Device $device, Sensor $sensor, GetSensorSamples $get): JsonResponse
    {
        [$from, $to] = $this->window($request);

        return response()->json(['data' => $get($sensor->id, $device->id, $from, $to)]);
    }

    /** @return array{0: Carbon, 1: Carbon} [from, to] */
    private function window(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $to = isset($validated['to']) ? Carbon::parse($validated['to']) : now();
        $from = isset($validated['from'])
            ? Carbon::parse($validated['from'])
            : $to->copy()->subSeconds((int) config('mymate.history.default_window', 3600));

        return [$from, $to];
    }
}
