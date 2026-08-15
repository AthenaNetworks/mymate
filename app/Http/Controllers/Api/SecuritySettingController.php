<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AuthSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The authentication-policy settings (admin). Currently just the mandatory-passkey toggle. `show`
 * also reports how many operators would be forced to enrol if it were switched on, so the UI can
 * warn before flipping it (wallboard/kiosk accounts on TVs are the ones to watch - mark them exempt).
 */
class SecuritySettingController extends Controller
{
    public function show(AuthSettings $settings): JsonResponse
    {
        return response()->json(['data' => $settings->publicView() + [
            'affected_operators' => $this->affectedOperators(),
        ]]);
    }

    public function update(Request $request, AuthSettings $settings): JsonResponse
    {
        $data = $request->validate(['passkey_required' => ['required', 'boolean']]);
        $settings->setPasskeyRequired($data['passkey_required']);

        return response()->json(['data' => $settings->publicView()]);
    }

    /** Operators who'd be forced to enrol a passkey: not exempt, and none registered yet. */
    private function affectedOperators(): int
    {
        return User::where('passkey_exempt', false)->whereDoesntHave('passkeys')->count();
    }
}
