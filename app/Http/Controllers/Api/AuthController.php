<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\UpdatePasswordRequest;
use App\Support\AuthSettings;
use App\Support\EngineLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Sanctum SPA (session/cookie) authentication. The same-origin React app
 * fetches `/sanctum/csrf-cookie`, then POSTs `/api/login`; the session cookie that
 * results authenticates the rest of `/api` and the private `map` channel.
 */
class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->only('email', 'password');

        if (! Auth::guard('web')->attempt($credentials, $request->boolean('remember'))) {
            EngineLog::warning('auth: failed login', ['email' => $request->input('email'), 'ip' => $request->ip()]);

            // No "user not found" leak - generic message on the email field.
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        $request->session()->regenerate(); // prevent session fixation

        return response()->json($this->me($request));
    }

    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    /** The current operator (drives the SPA auth guard + top-bar identity). */
    public function user(Request $request): JsonResponse
    {
        return response()->json($this->me($request));
    }

    /** Self-service password change - `current_password:web` already verified it. */
    public function updatePassword(UpdatePasswordRequest $request): Response
    {
        $user = $request->user();
        $user->update(['password' => $request->validated('password')]); // 'hashed' cast hashes it

        EngineLog::warning('auth: password changed', ['user_id' => $user->id, 'ip' => $request->ip()]);

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function me(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_admin' => $user->isAdmin(),
            'restricted' => $user->isRestricted(), // GitHub #28: confined to granted maps
            // Passkey state drives the SPA's post-login gate (enrol / 2FA challenge / straight in).
            'passkey_required' => app(AuthSettings::class)->passkeyRequired(),
            'has_passkey' => $user->passkeys()->exists(),
            'passkey_exempt' => (bool) $user->passkey_exempt,
            'passkey_stage' => $this->passkeyStage($request),
        ];
    }

    /**
     * What the SPA must do about passkeys after a password login:
     *  - verified: token auth, exempt, or already completed the ceremony this session -> into the app.
     *  - challenge: has a passkey but hasn't tapped it yet this session -> 2FA step.
     *  - enrol: passkeys are required and they have none -> forced to set one up.
     *  - none: no passkey and not required -> nothing to do.
     */
    private function passkeyStage(Request $request): string
    {
        $user = $request->user();

        // A real API key (not Sanctum's session TransientToken), an exempt account, or a session
        // that already completed the ceremony - all count as done.
        if ($user->currentAccessToken() instanceof PersonalAccessToken
            || $user->passkey_exempt
            || ($request->hasSession() && $request->session()->get('passkey_verified', false))) {
            return 'verified';
        }
        if ($user->passkeys()->exists()) {
            return 'challenge';
        }
        if (app(AuthSettings::class)->passkeyRequired()) {
            return 'enrol';
        }

        return 'none';
    }
}
