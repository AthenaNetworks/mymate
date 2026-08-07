<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProbeRequest;
use App\Http\Resources\ProbeResource;
use App\Models\Device;
use App\Models\Probe;
use App\Services\Probes\ProbeRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/** Service probe CRUD (GitHub #19): HTTP/TCP checks on a device, plus a run-now test and history. */
class ProbeController extends Controller
{
    public function index(Device $device): AnonymousResourceCollection
    {
        return ProbeResource::collection($device->probes()->orderBy('name')->get());
    }

    public function store(StoreProbeRequest $request, Device $device): JsonResponse
    {
        $probe = $device->probes()->create($request->validated());

        return (new ProbeResource($probe))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(StoreProbeRequest $request, Probe $probe): ProbeResource
    {
        $probe->update($request->validated());

        return new ProbeResource($probe->fresh());
    }

    public function destroy(Probe $probe): Response
    {
        $probe->delete();

        return response()->noContent();
    }

    /** Run the probe once now and persist the result, so the operator sees it without waiting for the tick. */
    public function test(Probe $probe, ProbeRunner $runner): JsonResponse
    {
        $probe->loadMissing('device:id,mgmt_ip');
        $result = $runner->run($probe);

        return response()->json(['data' => [
            'up' => $result->up,
            'latency_ms' => $result->latencyMs,
            'message' => $result->message,
            'cert_expires_at' => $result->certExpiresAt,
        ]]);
    }

    /** Recent status/latency history for a probe (for the trend sparkline). */
    public function samples(Probe $probe): JsonResponse
    {
        $rows = DB::table('probe_samples')
            ->where('probe_id', $probe->id)
            ->where('ts', '>=', now()->subDay())
            ->orderBy('ts')
            ->get(['ts', 'up', 'latency_ms']);

        return response()->json(['data' => $rows]);
    }
}
