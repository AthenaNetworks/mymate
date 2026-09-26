<?php

namespace App\Http\Middleware;

use App\Support\WallEmbedSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security response headers. CSP allows
 * 'unsafe-inline' styles because the map's live colour ramp (linkColor.ts)
 * and other components set colour/position via React inline `style`
 * attributes - a nonce-based policy isn't practical here. connect-src
 * allows any ws/wss host since the Reverb host (REVERB_HOST) is
 * deployment-configurable and may differ from APP_URL.
 *
 * Framing is DENY / frame-ancestors 'none' on every response bar one: the public wallboard page
 * (/wall/{token}, GitHub #15) gets frame-ancestors from the admin's embed allow-list when that
 * list isn't empty. X-Frame-Options can't express an allow-list, so it's dropped there and the
 * CSP carries it (every browser that matters honours frame-ancestors, and it wins over XFO).
 * Only the document being framed needs this - the page's own XHRs, scripts and images are never
 * "framed", so the API keeps DENY even for the wallboard's endpoints.
 *
 * A controller may set its own, stricter CSP (eg the sandboxed map background image). That's
 * kept and ours is sent alongside it as a second policy - browsers enforce both, so the strictest
 * wins and this middleware can never loosen a response.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');

        $ancestors = $this->wallFrameAncestors($request, $response);
        if ($ancestors === null) {
            $response->headers->set('X-Frame-Options', 'DENY');
        } else {
            $response->headers->remove('X-Frame-Options');
        }
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Geo-overlay map tiles are loaded straight from the tile provider, so its host(s) are
        // allowed in img-src. Configurable (point at an internal tile server, or leave empty).
        $tileHosts = trim((string) config('mymate.map.tile_csp_hosts', ''));
        $imgSrc = trim("img-src 'self' data: {$tileHosts}");

        $policy = implode('; ', [
            "default-src 'self'",
            "script-src 'self'",
            "style-src 'self' 'unsafe-inline'",
            $imgSrc,
            "font-src 'self' data:",
            "connect-src 'self' ws: wss:",
            'frame-ancestors '.($ancestors === null ? "'none'" : implode(' ', $ancestors)),
            "base-uri 'self'",
            "form-action 'self'",
        ]);
        $existing = $response->headers->all('content-security-policy');
        $response->headers->set('Content-Security-Policy', [...$existing, $policy]);

        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    /**
     * The origins allowed to frame this response, or null for "nobody". Only a successfully
     * served wallboard page qualifies - a 404 for a dead token, and every other route, stays DENY.
     *
     * @return list<string>|null
     */
    private function wallFrameAncestors(Request $request, Response $response): ?array
    {
        if (! $request->routeIs('wall.show') || ! $response->isSuccessful()) {
            return null;
        }

        $origins = app(WallEmbedSettings::class)->frameAncestors();

        return $origins === [] ? null : $origins;
    }
}
