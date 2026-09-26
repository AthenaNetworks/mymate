<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AgentResource;
use App\Models\Agent;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Manage remote agents (probes). Listing is readable by any operator; enrol/delete are
 * writes (admin-only via RestrictWritesToAdmins). Enrolment returns the plaintext token
 * ONCE - it's never retrievable afterwards.
 */
class AgentController extends Controller
{
    public function index(): JsonResponse
    {
        $agents = Agent::withCount(['devices', 'subnets'])->orderBy('name')->get();

        return AgentResource::collection($agents)->response();
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);

        [$agent, $token] = Agent::enrol($data['name']);

        // The one and only time the plaintext token is returned.
        return response()->json([
            'agent' => new AgentResource($agent->loadCount(['devices', 'subnets'])),
            'token' => $token,
        ], Response::HTTP_CREATED);
    }

    public function destroy(Agent $agent): Response|JsonResponse
    {
        // Deleting nulls agent_id on its devices/subnets via the FK, so they revert to central
        // polling. A device whose IP is already used centrally would then clash with the per-scope
        // unique index (GitHub #49) - refuse up front, naming them, instead of failing mid-delete.
        $clashes = Device::withoutGlobalScope('visibility')
            ->where('agent_id', $agent->id)
            ->whereIn('mgmt_ip', Device::withoutGlobalScope('visibility')->whereNull('agent_id')->select('mgmt_ip'))
            ->orderBy('name')
            ->get(['name', 'mgmt_ip']);

        if ($clashes->isNotEmpty()) {
            $list = $clashes->take(5)->map(fn ($d) => "{$d->name} ({$d->mgmt_ip})")->implode(', ')
                .($clashes->count() > 5 ? ', and '.($clashes->count() - 5).' more' : '');

            return response()->json([
                'message' => "Can't remove this agent yet: {$clashes->count()} of its devices share an IP with a centrally "
                    ."polled device, so they'd clash when moved back to central polling ({$list}). Move or delete those "
                    .'devices first.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $agent->delete();

        return response()->noContent();
    }
}
