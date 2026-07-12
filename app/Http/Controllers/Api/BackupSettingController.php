<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Backup\UpdateBackupSettingsRequest;
use App\Services\Backup\RustedClient;
use App\Support\BackupSettings;
use Illuminate\Http\JsonResponse;

/**
 * Connection config for the external **Rusted** backup engine. The API
 * token + default SSH password are encrypted at rest and never returned - {@see
 * BackupSettings::publicView()} exposes only `*_set` flags. Admin-only (route group).
 */
class BackupSettingController extends Controller
{
    public function show(BackupSettings $settings): JsonResponse
    {
        return response()->json(['data' => $settings->publicView()]);
    }

    public function update(UpdateBackupSettingsRequest $request, BackupSettings $settings): JsonResponse
    {
        $settings->save($request->validated());

        return response()->json(['data' => $settings->publicView()]);
    }

    /**
     * Reachability check - does Rusted answer on the configured URL/token? Returns only a
     * boolean (like {@see MailSettingController::test()}, no raw error is echoed so this
     * can't be used as an internal port-scan oracle). Rate-limited on the route.
     */
    public function test(RustedClient $client, BackupSettings $settings): JsonResponse
    {
        if (! $settings->configured()) {
            return response()->json(['ok' => false, 'error' => 'Set the backup engine URL and token first.'], 422);
        }

        return response()->json(['ok' => $client->healthy()]);
    }
}
