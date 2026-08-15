<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Personal API keys. An operator mints a bearer token for programmatic access (scripts, the
 * LibreNMS-style module, a dashboard). The token authenticates AS its owner through the same
 * `auth:sanctum` guard the SPA uses, so it inherits that operator's exact access - an admin's
 * key can write, a read-only viewer's key is read-only, a restricted operator's key sees only
 * their maps. Nothing extra to enforce: the existing RestrictWritesToAdmins / RestrictedAccess
 * middleware run for a token request just as they do for a session. Self-service, so every
 * operator manages their own keys (see the exemption in RestrictWritesToAdmins).
 *
 * The plaintext is shown exactly once, on create; only the hash is stored.
 */
class ApiTokenController extends Controller
{
    /** This operator's keys (never the token itself - only metadata). */
    public function index(Request $request): JsonResponse
    {
        $tokens = $request->user()->tokens()->latest()->get()->map(fn ($t) => [
            'id' => $t->id,
            'name' => $t->name,
            'last_used_at' => $t->last_used_at,
            'created_at' => $t->created_at,
        ]);

        return response()->json($tokens);
    }

    /** Mint a key and return the plaintext once. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
        ]);

        // Full abilities: the token is capped by the OWNER's tier (admin/viewer/restricted), which
        // the middleware enforces per request - not by a separate token scope.
        $token = $request->user()->createToken($data['name'], ['*']);

        return response()->json([
            'id' => $token->accessToken->getKey(),
            'name' => $token->accessToken->name,
            'token' => $token->plainTextToken, // shown once, never retrievable again
        ], 201);
    }

    /** Revoke one of this operator's keys. Scoped to their own tokens so nobody can revoke another's. */
    public function destroy(Request $request, int $id): Response
    {
        $request->user()->tokens()->whereKey($id)->delete();

        return response()->noContent();
    }
}
