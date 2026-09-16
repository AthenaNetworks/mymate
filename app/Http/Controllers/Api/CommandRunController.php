<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\Tools\RunCommandJob;
use App\Models\CommandTemplate;
use App\Models\CommandRun;
use App\Models\CommandRunTarget;
use App\Models\Device;
use App\Services\Tools\ToolRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Support\BackupSettings;
use Carbon\CarbonImmutable;

class CommandRunController extends Controller
{
    public function start(Request $request, BackupSettings $settings): JsonResponse
    {
        if (! $settings->configured()) {
            return response()->json(['message' => 'The backup engine (Rusted) is not configured yet.'], 422);
        }

        $template = null;
        if ($request->filled('template_id')) {
            $template = CommandTemplate::query()->find($request->integer('template_id'));
            if ($template === null || ! $template->enabled) {
                throw ValidationException::withMessages(['template_id' => 'The selected command template is not enabled.']);
            }
            if (! $request->filled('template_updated_at') || ! $request->filled('command_hash')) {
                throw ValidationException::withMessages(['template_id' => 'Template version confirmation is required.']);
            }
            try {
                $requestedUpdatedAt = CarbonImmutable::parse((string) $request->input('template_updated_at'));
            } catch (\Throwable) {
                throw ValidationException::withMessages(['template_updated_at' => 'The template version is invalid.']);
            }
            if (! CarbonImmutable::parse((string) $template->updated_at)->equalTo($requestedUpdatedAt)) {
                throw ValidationException::withMessages(['template_id' => 'The selected command template changed. Refresh and confirm again.']);
            }
            if (! hash_equals($template->commandHash(), (string) $request->input('command_hash'))) {
                throw ValidationException::withMessages(['command_hash' => 'The command template hash does not match.']);
            }
        }

        $command = trim((string) ($template?->command ?? $request->input('command', '')));
        if (! $request->boolean('confirm')) {
            throw ValidationException::withMessages(['confirm' => 'Explicit confirmation is required before executing a command.']);
        }
        if ($command === '') {
            throw ValidationException::withMessages(['command' => 'Enter a command.']);
        }
        if (mb_strlen($command) > 4096) {
            throw ValidationException::withMessages(['command' => 'The command is too long (limit 4096 characters).']);
        }

        $ids = $request->input('device_ids');
        if (! is_array($ids) || $ids === []) {
            throw ValidationException::withMessages(['device_ids' => 'Select at least one device.']);
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (count($ids) > 100) {
            throw ValidationException::withMessages(['device_ids' => 'A command run may target at most 100 devices.']);
        }

        $timeout = (int) $request->input('timeout', $template?->timeout_seconds ?? 30);
        if ($template !== null && $request->filled('timeout') && $timeout > $template->timeout_seconds) {
            throw ValidationException::withMessages(['timeout' => 'A template run cannot exceed its saved timeout.']);
        }
        if ($timeout < 1 || $timeout > 60) {
            throw ValidationException::withMessages(['timeout' => 'Timeout must be between 1 and 60 seconds.']);
        }

        $devices = Device::query()->whereIn('id', $ids)->get()->keyBy('id');
        if ($devices->count() !== count($ids)) {
            throw ValidationException::withMessages(['device_ids' => 'One or more selected devices no longer exists.']);
        }

        $runId = (string) Str::uuid();
        $rows = [];
        foreach ($ids as $id) {
            $device = $devices->get($id);
            $rows[(string) $id] = [
                'device_id' => $device->id,
                'name' => $device->name,
                'status' => 'queued',
                'output' => '',
                'error' => null,
            ];
        }

        CommandRun::create([
            'id' => $runId,
            'user_id' => $request->user()->id,
            'template_id' => $template?->id,
            'command' => $command,
            'command_hash' => hash('sha256', $command),
            'timeout_seconds' => $timeout,
            'target_count' => count($rows),
            'status' => 'running',
            'confirmed_at' => now(),
        ]);
        foreach ($ids as $id) {
            $device = $devices->get($id);
            CommandRunTarget::create([
                'run_id' => $runId,
                'device_id' => $device->id,
                'device_name' => $device->name,
                'status' => 'queued',
            ]);
        }

        ToolRun::start($runId, 'command', count($rows).' device(s)', $request->user()->id, [
            'command' => $command,
            'command_hash' => hash('sha256', $command),
            'template_id' => $template?->id,
            'template_updated_at' => $template?->updated_at?->toISOString(),
            'timeout' => $timeout,
            'complete' => false,
            'devices' => $rows,
        ]);

        foreach ($ids as $id) {
            RunCommandJob::dispatch($runId, $id, $command, $timeout);
        }

        return response()->json(['run_id' => $runId, 'kind' => 'command', 'status' => 'running'], 202);
    }

    public function show(Request $request, string $runId): JsonResponse
    {
        $snapshot = ToolRun::get($runId);
        abort_if($snapshot === null, 404);
        $owner = ToolRun::owner($runId);
        abort_unless($request->user()->isAdmin() || ($owner !== null && $owner === $request->user()->id), 403);

        return response()->json($snapshot);
    }

    public function stop(Request $request, string $runId): JsonResponse
    {
        $snapshot = ToolRun::get($runId);
        abort_if($snapshot === null, 404);
        abort_unless($request->user()->isAdmin() || ToolRun::owner($runId) === $request->user()->id, 403);
        ToolRun::requestStop($runId);

        return response()->json(['status' => 'stopping']);
    }
}
