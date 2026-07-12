<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AgentResource;
use App\Models\Agent;
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

    public function destroy(Agent $agent): Response
    {
        // Nulls agent_id on its devices/subnets (they revert to central polling) via the FK.
        $agent->delete();

        return response()->noContent();
    }
}
