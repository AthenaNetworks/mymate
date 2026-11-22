<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse a disabled operator account (`users.disabled_at`), whoever disabled it - an admin, or SSO
 * when the provider stopped granting a My Mate role. Runs on the authenticated API group (session
 * AND API keys - a disabled account's keys stop working too) and on the web group, which covers
 * the broadcast-auth route, so a disabled operator also can't (re)join the live channels.
 *
 * A session is ended on the spot. An API / broadcast request gets 401 with
 * `code: account_disabled`, so the SPA drops back to the login screen; a plain page load just
 * carries on signed out.
 */
class EnsureAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        // On the web group nothing has authenticated yet - look at the session user directly.
        $user = $request->user() ?? Auth::guard('web')->user();

        if ($user !== null && $user->disabled_at !== null) {
            if (Auth::guard('web')->check()) {
                Auth::guard('web')->logout();
                if ($request->hasSession()) {
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();
                }
            }

            // A page load (the SPA shell) carries on, now signed out, and shows the login screen.
            if (! $request->expectsJson() && ! $request->is('api/*', 'broadcasting/*')) {
                return $next($request);
            }

            return response()->json([
                'code' => 'account_disabled',
                'message' => 'This account has been disabled.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
