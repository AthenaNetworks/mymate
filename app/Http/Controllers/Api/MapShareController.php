<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Map;
use App\Models\MapShare;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Manage a map's public wallboard links (GitHub #15). Admin-only (the route group applies the
 * `admin` middleware); viewers can see the links exist but only an admin can mint or revoke one.
 */
class MapShareController extends Controller
{
    /** @return array<string, mixed> */
    private function present(MapShare $share): array
    {
        return [
            'id' => $share->id,
            'label' => $share->label,
            'enabled' => $share->enabled,
            'url' => $share->url(),
            'last_viewed_at' => $share->last_viewed_at,
            'created_at' => $share->created_at,
        ];
    }

    public function index(Map $map): JsonResponse
    {
        $shares = $map->shares()->latest()->get()->map(fn ($s) => $this->present($s))->all();

        return response()->json(['data' => $shares]);
    }

    public function store(Request $request, Map $map): JsonResponse
    {
        $validated = $request->validate([
            'label' => ['nullable', 'string', 'max:120'],
        ]);

        $share = $map->shares()->create([
            'token' => MapShare::newToken(),
            'label' => $validated['label'] ?? null,
            'enabled' => true,
        ]);

        return response()->json(['data' => $this->present($share)], Response::HTTP_CREATED);
    }

    public function update(Request $request, Map $map, MapShare $share): JsonResponse
    {
        abort_unless($share->map_id === $map->id, 404);

        $validated = $request->validate([
            'label' => ['sometimes', 'nullable', 'string', 'max:120'],
            'enabled' => ['sometimes', 'boolean'],
        ]);

        $share->update($validated);

        return response()->json(['data' => $this->present($share)]);
    }

    public function destroy(Map $map, MapShare $share): Response
    {
        abort_unless($share->map_id === $map->id, 404);

        $share->delete();

        return response()->noContent();
    }
}
