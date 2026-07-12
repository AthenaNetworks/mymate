<?php

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum SPA: the api group is stateful (cookie/session) so the same-origin
        // React app authenticates by session + CSRF.
        $middleware->statefulApi();

        // Trust the reverse proxy in front of us so HTTPS is detected from X-Forwarded-Proto
        // (SSL facility). With TLS terminated upstream - a Cloudflare Tunnel or the operator's
        // reverse proxy (methods 1 & 2) - nginx forwards the header and Laravel needs to
        // honour it, else it generates http URLs and refuses to set the secure session cookie.
        // The only direct client is the local nginx; the operator's edge proxy must strip any
        // client-supplied X-Forwarded-* (standard practice) - see deploy/ssl/README.md.
        $middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO);

        // Baseline security response headers on every request, web + api (
        // hardening - closes the security-checklist.md gap).
        $middleware->append(SecurityHeaders::class);

        // `admin` gates the operator-management write routes.
        $middleware->alias(['admin' => EnsureAdmin::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // JSON errors for the API and for any JSON request (e.g. the SPA's web-group
        // /login, which expects JSON) - otherwise validation 422s become HTML redirects.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
