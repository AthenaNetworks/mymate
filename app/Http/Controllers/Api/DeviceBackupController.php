<?php

namespace App\Http\Controllers\Api;

use App\Actions\Backup\DeregisterBackupDevice;
use App\Actions\Backup\RegisterBackupDevice;
use App\Enums\BackupStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Backup\UpdateDeviceBackupConfigRequest;
use App\Http\Resources\DeviceResource;
use App\Jobs\RunDeviceBackupJob;
use App\Models\Device;
use App\Services\Backup\RustedClient;
use App\Support\BackupSettings;
use App\Support\EngineLog;
use App\Support\RustedDrivers;
use Illuminate\Http\JsonResponse;

/**
 * Per-device config backups: opt-in/driver config, "back up now", and
 * read-through history/latest-config proxied from the Rusted engine. Writes are admin-only
 * (route group); the history/config GETs are readable by any operator (like the rest of the
 * inspector). Rusted itself does the SSH capture + git storage - this is the control plane.
 */
class DeviceBackupController extends Controller
{
    /**
     * Turn backups on/off for a device and set its driver. Enabling without a resolvable
     * driver is rejected up-front (a pure local check - no Rusted round-trip). Registering
     * with Rusted is best-effort (it's re-done idempotently before every run), so the config
     * still saves when the engine is momentarily unreachable.
     */
    public function config(
        UpdateDeviceBackupConfigRequest $request,
        Device $device,
        RegisterBackupDevice $register,
        DeregisterBackupDevice $deregister,
    ): JsonResponse {
        $enabled = (bool) $request->boolean('backup_enabled');
        $driver = $request->input('backup_driver') ?: ($enabled ? RustedDrivers::suggestFor($device) : $device->backup_driver);

        if ($enabled && $driver === null) {
            return response()->json([
                'message' => 'Pick a backup driver for this device (its vendor could not be matched to one automatically).',
                'errors' => ['backup_driver' => ['A driver is required to enable backups for this device.']],
            ], 422);
        }

        $device->forceFill(['backup_enabled' => $enabled, 'backup_driver' => $driver])->save();

        try {
            $enabled ? $register($device) : $deregister($device);
        } catch (\Throwable $e) {
            // Non-fatal: the config is saved; registration retries before the next run.
            EngineLog::warning('backup: register on config change failed', [
                'device_id' => $device->id,
                'error' => $e->getMessage(),
            ]);
        }

        return (new DeviceResource($device->refresh()))->response();
    }

    /** Queue a backup now. Marks the device `pending` immediately for instant UI feedback. */
    public function run(Device $device, BackupSettings $settings): JsonResponse
    {
        if (! $settings->configured()) {
            return response()->json(['message' => 'The backup engine (Rusted) is not configured yet - set it up in Settings.'], 422);
        }

        $device->forceFill(['backup_status' => BackupStatus::Pending, 'backup_message' => null])->save();
        RunDeviceBackupJob::dispatch($device->id);

        return (new DeviceResource($device))->response()->setStatusCode(202);
    }

    /**
     * Bootstrap key-based SSH over the RouterOS API (MikroTik can't export over the API):
     * Rusted installs a generated key and returns it; we store it as the device's SSH
     * credential and switch it to SSH backups. Reports whether SSH had to be enabled.
     */
    public function provisionKey(Device $device, \App\Actions\Backup\ProvisionSshKey $provision, BackupSettings $settings): JsonResponse
    {
        if (! $settings->configured()) {
            return response()->json(['message' => 'The backup engine (Rusted) is not configured yet - set it up in Settings.'], 422);
        }

        try {
            $result = $provision($device);
        } catch (\Throwable $e) {
            EngineLog::warning('backup: ssh-key provisioning failed', ['device_id' => $device->id, 'error' => $e->getMessage()]);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => $result['ssh_enabled_by']
                ? "SSH key installed and the SSH service was enabled (port {$result['ssh_port']})."
                : "SSH key installed (SSH already on, port {$result['ssh_port']}).",
            'device' => new DeviceResource($device->fresh()),
            'ssh_port' => $result['ssh_port'],
            'ssh_enabled' => $result['ssh_enabled'],
            'ssh_enabled_by' => $result['ssh_enabled_by'],
        ]);
    }

    /**
     * Backup history for a device, proxied live from Rusted (newest first). Returns [] when
     * the engine is unconfigured/unreachable rather than erroring - the inspector just shows
     * "no history yet".
     */
    public function history(Device $device, RustedClient $client, BackupSettings $settings): JsonResponse
    {
        if (! $settings->configured()) {
            return response()->json(['data' => []]);
        }

        try {
            return response()->json(['data' => $client->history(RegisterBackupDevice::rustedName($device))]);
        } catch (\Throwable $e) {
            EngineLog::warning('backup: history fetch failed', ['device_id' => $device->id, 'error' => $e->getMessage()]);

            return response()->json(['data' => []]);
        }
    }

    /** Latest stored config text for a device (proxied from Rusted), or null. */
    public function latest(Device $device, RustedClient $client, BackupSettings $settings): JsonResponse
    {
        if (! $settings->configured()) {
            return response()->json(['data' => ['config' => null]]);
        }

        try {
            $config = $client->latestConfig(RegisterBackupDevice::rustedName($device));
        } catch (\Throwable $e) {
            EngineLog::warning('backup: config fetch failed', ['device_id' => $device->id, 'error' => $e->getMessage()]);
            $config = null;
        }

        return response()->json(['data' => ['config' => $config]]);
    }

    /** Config version history (git log) for a device, newest first. */
    public function versions(Device $device, RustedClient $client, BackupSettings $settings): JsonResponse
    {
        if (! $settings->configured()) {
            return response()->json(['data' => []]);
        }

        try {
            $versions = $client->versions(RegisterBackupDevice::rustedName($device));
        } catch (\Throwable $e) {
            EngineLog::warning('backup: versions fetch failed', ['device_id' => $device->id, 'error' => $e->getMessage()]);
            $versions = [];
        }

        return response()->json(['data' => $versions]);
    }

    /** The stored config at a specific commit (?commit=), for viewing an old version. */
    public function configAt(\Illuminate\Http\Request $request, Device $device, RustedClient $client): JsonResponse
    {
        $commit = (string) $request->query('commit', '');
        $config = null;
        try {
            $config = $commit !== '' ? $client->configAt(RegisterBackupDevice::rustedName($device), $commit) : null;
        } catch (\Throwable $e) {
            EngineLog::warning('backup: config-at-commit failed', ['device_id' => $device->id, 'error' => $e->getMessage()]);
        }

        return response()->json(['data' => ['config' => $config]]);
    }

    /** Unified diff of a device's config - ?from=<hash> alone shows what that backup changed. */
    public function diff(\Illuminate\Http\Request $request, Device $device, RustedClient $client): JsonResponse
    {
        $from = (string) $request->query('from', '');
        $to = (string) $request->query('to', '');
        $diff = null;
        try {
            $diff = $from !== '' ? $client->diff(RegisterBackupDevice::rustedName($device), $from, $to) : null;
        } catch (\Throwable $e) {
            EngineLog::warning('backup: diff failed', ['device_id' => $device->id, 'error' => $e->getMessage()]);
        }

        return response()->json(['data' => ['diff' => $diff]]);
    }
}
