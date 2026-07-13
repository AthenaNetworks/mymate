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

    /** Queue a backup now for every backup-enabled device. */
    public function runAll(BackupSettings $settings): JsonResponse
    {
        if (! $settings->configured()) {
            return response()->json(['message' => 'The backup engine (Rusted) is not configured yet.'], 422);
        }
        $ids = \App\Models\Device::where('backup_enabled', true)->pluck('id');
        foreach ($ids as $id) {
            \App\Jobs\RunDeviceBackupJob::dispatch($id);
        }

        return response()->json(['queued' => $ids->count()], 202);
    }

    /** The automatic-backup schedule (cadence + on/off). */
    public function schedule(\App\Support\BackupSchedule $schedule): JsonResponse
    {
        return response()->json(['data' => $schedule->get()]);
    }

    /** Save the automatic-backup schedule. */
    public function updateSchedule(\Illuminate\Http\Request $request, \App\Support\BackupSchedule $schedule): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'frequency' => ['required', \Illuminate\Validation\Rule::in(\App\Support\BackupSchedule::FREQUENCIES)],
            'hour' => ['required', 'integer', 'min:0', 'max:23'],
            'weekday' => ['required', 'integer', 'min:0', 'max:6'],
        ]);
        $schedule->save($validated);

        return response()->json(['data' => $schedule->get()]);
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
