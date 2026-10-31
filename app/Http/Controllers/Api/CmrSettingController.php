<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cmr\UpdateCmrSettingsRequest;
use App\Services\Cmr\CmrClient;
use App\Support\CmrSettings;
use Illuminate\Http\JsonResponse;

/**
 * Connection config for the CMR controller sidecar, plus what an operator needs to point
 * MikroTik devices at it: the host/port and the certificate fingerprint they pin. The token is
 * encrypted at rest and never returned. Admin-only (route group).
 */
class CmrSettingController extends Controller
{
    public function show(CmrSettings $settings, CmrClient $client): JsonResponse
    {
        return response()->json(['data' => $this->view($settings, $client)]);
    }

    public function update(UpdateCmrSettingsRequest $request, CmrSettings $settings, CmrClient $client): JsonResponse
    {
        $settings->save($request->validated());

        return response()->json(['data' => $this->view($settings, $client)]);
    }

    /** Reachability check. Only a boolean comes back, so it can't be used as a port-scan oracle. */
    public function test(CmrClient $client, CmrSettings $settings): JsonResponse
    {
        if (! $settings->configured()) {
            return response()->json(['ok' => false, 'error' => 'Set the controller URL and token first.'], 422);
        }

        return response()->json(['ok' => $client->healthy()]);
    }

    /** @return array<string,mixed> */
    private function view(CmrSettings $settings, CmrClient $client): array
    {
        // The identity is the daemon's: read live (a 10s-capped loopback call), null when it's down.
        return $settings->publicView() + ['identity' => $settings->configured() ? $client->identity() : null];
    }
}
