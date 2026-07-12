<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Setting\UpdateSettingsRequest;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;

/**
 * Operator-editable engine tunables. The loop + history maintenance
 * read these live, so a change takes effect without restarting workers.
 */
class SettingController extends Controller
{
    public function index(Settings $settings): JsonResponse
    {
        return response()->json(['data' => $settings->effective()]);
    }

    public function update(UpdateSettingsRequest $request, Settings $settings): JsonResponse
    {
        foreach ($request->validated()['settings'] as $pair) {
            if (array_key_exists($pair['key'], Settings::SCHEMA)) {
                $settings->set($pair['key'], (int) $pair['value']);
            }
        }

        return response()->json(['data' => $settings->effective()]);
    }
}
