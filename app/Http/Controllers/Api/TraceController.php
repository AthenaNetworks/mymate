<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RunTraceJob;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Live MTR trace from this server to a device's own mgmt IP. The trace runs in
 * RunTraceJob (isolated `trace` queue) and streams its snapshot into
 * `Cache::get("trace:{runId}")`; this controller only starts/reads/stops that cache
 * entry - no trace state lives in the database. It can only ever probe the device's
 * own IP, so it's harmless for a non-admin to drive - see the exemption for these
 * route names in RestrictWritesToAdmins.
 */
class TraceController extends Controller
{
    private const CACHE_TTL_MINUTES = 15;

    /**
     * Kick off a trace. Seeds the cache with an empty "running" snapshot before
     * dispatching so an immediate GET (the frontend starts polling right after this
     * returns) is a 200, not a 404 race against the queue worker picking the job up.
     */
    public function start(Request $request, Device $device): JsonResponse
    {
        if (! $device->mgmt_ip) {
            return response()->json(['message' => 'This device has no management IP to trace.'], 422);
        }

        $rounds = max(5, min(120, (int) $request->input('rounds', 60)));
        $runId = (string) Str::uuid();

        Cache::put("trace:{$runId}", [
            'run_id' => $runId,
            'device_id' => $device->id,
            'status' => 'running',
            'target' => $device->mgmt_ip,
            'rounds_total' => $rounds,
            'rounds_done' => 0,
            'error' => null,
            'hops' => [],
        ], now()->addMinutes(self::CACHE_TTL_MINUTES));

        // Remember who started it (a separate key the job's snapshot rewrites never touch) so
        // only the starter or an admin can stop it - not any other operator on the same device.
        Cache::put("trace:{$runId}:owner", $request->user()->id, now()->addMinutes(self::CACHE_TTL_MINUTES));

        RunTraceJob::dispatch($device->id, $runId, $device->mgmt_ip, $rounds);

        return response()->json(['run_id' => $runId, 'status' => 'running'], 202);
    }

    /** Current snapshot for a run. 404s on an unknown/expired run id or a device mismatch. */
    public function show(Device $device, string $runId): JsonResponse
    {
        return response()->json(Arr::except($this->ownedSnapshot($device, $runId), ['device_id']));
    }

    /**
     * Flags the run to stop; RunTraceJob polls this flag and exits within one poll
     * cycle (killing the mtr process), then writes a final status=stopped snapshot.
     */
    public function stop(Request $request, Device $device, string $runId): Response
    {
        $this->ownedSnapshot($device, $runId); // 404s before setting a flag for a foreign run

        // Only the operator who started this run (or an admin) may cancel it, so one operator
        // can't kill another's live trace on a shared device.
        $owner = Cache::get("trace:{$runId}:owner");
        abort_unless($request->user()->isAdmin() || ($owner !== null && $owner === $request->user()->id), 403);

        Cache::put("trace:{$runId}:stop", true, now()->addMinutes(self::CACHE_TTL_MINUTES));

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function ownedSnapshot(Device $device, string $runId): array
    {
        $snapshot = Cache::get("trace:{$runId}");

        if ($snapshot === null || (int) $snapshot['device_id'] !== $device->id) {
            abort(404);
        }

        return $snapshot;
    }
}
