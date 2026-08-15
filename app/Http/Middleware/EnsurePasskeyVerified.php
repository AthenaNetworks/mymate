<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Api\PasskeyController;
use App\Support\AuthSettings;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates session logins behind a passkey second factor. Runs after `auth:sanctum`, alongside
 * RestrictWritesToAdmins / RestrictedAccess. An operator must complete a passkey ceremony this
 * session (marked `passkey_verified` by {@see PasskeyController}) when
 * either passkeys are required fleet-wide, or they've voluntarily registered one (opt-in 2FA).
 *
 * Deliberately does NOT touch:
 *  - API keys (bearer tokens): a token request carries a currentAccessToken and is never gated, so
 *    scripts/integrations keep working regardless of the passkey policy.
 *  - Exempt operators (passkey_exempt): eg a wallboard/kiosk on a TV that can't do WebAuthn.
 *  - The handful of routes needed to actually get verified (logout, the passkey ceremony, /user).
 *
 * A blocked request gets 423 (Locked) with `code: passkey_required` - distinct from a 401
 * (not logged in) so the SPA can show the passkey step instead of the login screen.
 */
class EnsurePasskeyVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null) {
            return $next($request);
        }
        // API keys are never gated - only cookie/session logins. Note a *session* request via
        // Sanctum also carries a token (a TransientToken), so we must check for a real
        // PersonalAccessToken specifically, not just any token.
        if ($user->currentAccessToken() instanceof PersonalAccessToken) {
            return $next($request);
        }
        // Exempt accounts (wallboard/kiosk) sail through untouched.
        if ($user->passkey_exempt) {
            return $next($request);
        }
        // Let a not-yet-verified operator reach the pages that let them verify.
        if ($request->routeIs('logout', 'user', 'passkeys.*')) {
            return $next($request);
        }

        $required = app(AuthSettings::class)->passkeyRequired();
        $mustVerify = $required || $user->passkeys()->exists();
        // No session means the ceremony can't have happened this session - treat as unverified.
        $verified = $request->hasSession() && $request->session()->get('passkey_verified', false);

        if ($mustVerify && ! $verified) {
            return response()->json([
                'code' => 'passkey_required',
                'message' => 'A passkey is required to continue.',
            ], Response::HTTP_LOCKED);
        }

        return $next($request);
    }
}
