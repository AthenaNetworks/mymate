<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\FrameAncestors;
use App\Support\WallEmbedSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Which origins may embed the public wallboard in an iframe (GitHub #15). Admin-only (the route
 * sits in the `admin` group). An invalid origin is a 422 naming the bad entry rather than being
 * silently dropped, so the admin knows exactly what didn't take.
 */
class WallEmbedSettingController extends Controller
{
    public function show(WallEmbedSettings $settings): JsonResponse
    {
        return response()->json(['data' => $settings->publicView()]);
    }

    public function update(Request $request, WallEmbedSettings $settings): JsonResponse
    {
        $data = $request->validate([
            'frame_ancestors' => ['present', 'array', 'max:'.FrameAncestors::MAX],
            'frame_ancestors.*' => ['required', 'string', 'max:255', function (string $attr, mixed $value, \Closure $fail): void {
                if (! is_string($value) || FrameAncestors::normalise($value) === null) {
                    $fail('"'.(is_string($value) ? $value : '?').'" is not a valid origin. Use scheme://host[:port], eg https://intranet.example.com');
                }
            }],
        ]);

        $settings->setFrameAncestors($data['frame_ancestors']);

        return response()->json(['data' => $settings->publicView()]);
    }
}
