<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Confine a restricted operator (GitHub #28) to the read-only map-viewing surface. The global
 * scopes on Device/Map/Link already limit *which* rows they see and route-model binding 404s the
 * rest, but sibling endpoints (outages, alert history, discovery, agents, config backups, settings)
 * query device-referencing tables directly and would leak names. Rather than scope each one, this
 * allows a restricted user only the endpoints the map + device inspector need and refuses the rest.
 *
 * Runs after auth:sanctum. Admins and ordinary (unrestricted) operators bypass entirely.
 */
class RestrictedAccess
{
    /** Route-name prefixes a restricted operator may reach (reads; writes are still admin-only). */
    private const ALLOWED = [
        'user', 'account.password', 'map.config',
        'maps', 'links', 'interfaces', 'devices', 'probes',
    ];

    /** Carve-outs inside an allowed prefix that stay off-limits (config backups carry secrets). */
    private const DENIED = ['devices.backups'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null || ! $user->isRestricted()) {
            return $next($request);
        }

        $name = (string) ($request->route()?->getName() ?? '');
        $allowed = self::matches($name, self::ALLOWED) && ! self::matches($name, self::DENIED);

        abort_unless($allowed, 403, 'Your account is limited to the maps you have been granted.');

        return $next($request);
    }

    /**
     * Match a route name against a set of name segments on a dot boundary: the name must equal a
     * segment or start with "<segment>.". This stops "user" from also matching "users.index" (the
     * roster) - a prefix match would leak it.
     *
     * @param  array<string>  $segments
     */
    private static function matches(string $name, array $segments): bool
    {
        foreach ($segments as $segment) {
            if ($name === $segment || str_starts_with($name, $segment.'.')) {
                return true;
            }
        }

        return false;
    }
}
