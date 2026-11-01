<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CommandTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CommandTemplateController extends Controller
{
    public function index(): JsonResponse
    {
        $templates = CommandTemplate::query()
            ->orderBy('name')
            ->get()
            ->map(fn (CommandTemplate $template): array => $template->toApiArray())
            ->values();

        return response()->json(['templates' => $templates]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $userId = $request->user()->id;
        $template = CommandTemplate::create([
            ...$data,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        return response()->json(['template' => $template->toApiArray()], 201);
    }

    public function update(Request $request, CommandTemplate $commandTemplate): JsonResponse
    {
        $data = $this->validated($request, $commandTemplate);
        $commandTemplate->update([...$data, 'updated_by' => $request->user()->id]);

        return response()->json(['template' => $commandTemplate->fresh()->toApiArray()]);
    }

    public function destroy(CommandTemplate $commandTemplate): JsonResponse
    {
        $commandTemplate->delete();

        return response()->json(['status' => 'deleted']);
    }

    private function validated(Request $request, ?CommandTemplate $existing = null): array
    {
        $request->merge(['name' => trim((string) $request->input('name', ''))]);
        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique('command_templates', 'name')->ignore($existing?->id),
            ],
            'command' => ['required', 'string', 'max:4096'],
            'timeout' => ['required', 'integer', 'min:1', 'max:60'],
            'enabled' => ['sometimes', 'boolean'],
        ]);

        $command = trim($data['command']);
        if ($command === '') {
            throw ValidationException::withMessages(['command' => 'Enter a command.']);
        }

        return [
            'name' => trim($data['name']),
            'command' => $command,
            'timeout_seconds' => (int) $data['timeout'],
            'enabled' => (bool) ($data['enabled'] ?? true),
        ];
    }
}
