<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate the operator-management write routes. A normal operator may *view* the
 * roster (the `index` route is open + tier-shaped in the controller) but only an admin may
 * create / edit / delete accounts - everything behind this middleware returns 403 otherwise.
 * Runs after `auth:sanctum`, so `user()` is always present here.
 */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Administrator access required.');

        return $next($request);
    }
}
