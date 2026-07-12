<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * App-wide read-only tier. A normal operator may *view* the whole console but
 * may not change anything. The app first shipped a single tier where every logged-in operator
 * could edit, and only the user-management endpoints were gated. This closes the rest:
 * any state-changing request (non-safe HTTP method) from a non-admin is refused with 403,
 * so a viewer cannot delete a device, drop a link, run a scan, trigger an upgrade, etc.,
 * regardless of whether the UI still shows the control.
 *
 * Runs after `auth:sanctum`, so `user()` is present. Admins bypass entirely. The only
 * write a non-admin keeps is changing *their own* password (a self-service action, not a
 * change to the monitored fleet).
 */
class RestrictWritesToAdmins
{
    /** Safe (read-only) HTTP methods - never gated. */
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /** Route names a non-admin may still POST/PUT to (self-service only). */
    private const SELF_SERVICE_ROUTES = ['account.password.update'];

    public function handle(Request $request, Closure $next): Response
    {
        $isWrite = ! in_array($request->getMethod(), self::SAFE_METHODS, true);

        if ($isWrite
            && ! $request->user()?->isAdmin()
            && ! $request->routeIs(self::SELF_SERVICE_ROUTES)
        ) {
            abort(403, 'Read-only operator - an administrator must make this change.');
        }

        return $next($request);
    }
}
