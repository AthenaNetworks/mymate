<?php

namespace App\Http\Controllers\Api;

use App\Actions\System\FactoryReset;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Danger zone: wipe all monitoring data and keep only admin accounts. Admin-only (route group)
 * and gated on the caller re-entering their own password, so a stray click or stolen session
 * can't nuke the install.
 */
class FactoryResetController extends Controller
{
    public function store(Request $request, FactoryReset $reset): JsonResponse
    {
        $request->validate(['password' => ['required', 'string']]);

        // Re-confirm against the authenticated (Sanctum) user rather than the web guard, so the
        // check is right whether the caller came in via SPA session or a token.
        if (! Hash::check($request->string('password'), (string) $request->user()->password)) {
            throw ValidationException::withMessages(['password' => 'The password is incorrect.']);
        }

        $reset();

        return response()->json(['message' => 'Factory reset complete. All monitoring data was cleared; admin accounts were kept.']);
    }
}
