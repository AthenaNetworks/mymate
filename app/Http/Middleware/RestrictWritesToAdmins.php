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
 * writes a non-admin keeps are changing *their own* password (a self-service action, not
 * a change to the monitored fleet) and the OPERATOR_ACTION_ROUTES below (harmless writes
 * that never touch the fleet's config either).
 */
class RestrictWritesToAdmins
{
    /** Safe (read-only) HTTP methods - never gated. */
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /** Route names a non-admin may still POST/PUT to (self-service only). */
    private const SELF_SERVICE_ROUTES = ['account.password.update', 'api-tokens.store', 'api-tokens.destroy', 'passkeys.*'];

    /**
     * Route names a non-admin may still POST/DELETE to because the action is harmless:
     * it can only affect the requesting operator's own view, never the monitored fleet's
     * config. Live device trace start/stop was the first example - the target is locked to
     * the device's own mgmt IP. The Tools page (tools.*) is the same idea: ping/trace/sweep/
     * port-scan/bgp are read-only network diagnostics that don't mutate any monitored config.
     */
    private const OPERATOR_ACTION_ROUTES = ['devices.trace.start', 'devices.trace.stop', 'tools.*'];

    public function handle(Request $request, Closure $next): Response
    {
        $isWrite = ! in_array($request->getMethod(), self::SAFE_METHODS, true);

        if ($isWrite
            && ! $request->user()?->isAdmin()
            && ! $request->routeIs(self::SELF_SERVICE_ROUTES)
            && ! $request->routeIs(self::OPERATOR_ACTION_ROUTES)
        ) {
            abort(403, 'Read-only operator - an administrator must make this change.');
        }

        return $next($request);
    }
}
