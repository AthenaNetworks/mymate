<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

// Auth - on the web group so the session + CSRF are always
// present (the SPA fetches /sanctum/csrf-cookie first). Login is rate-limited.
Route::post('login', [AuthController::class, 'login'])->middleware('throttle:6,1')->name('login');
Route::post('logout', [AuthController::class, 'logout'])->name('logout');

// RouterOS package download for routers (/tool/fetch). Unauthenticated by necessity - a
// router carries no session - but gated by the package's unguessable token. Must be declared
// before the SPA catch-all below.
Route::get('/rospkg/{token}', \App\Http\Controllers\RouterosPackageDownloadController::class)
    ->where('token', '[A-Za-z0-9]+')->name('routeros.package.download');

// The SPA shell must NOT be cached: it references hashed build assets + injects the
// per-instance boot flags (e.g. demo mode). A cached shell serves stale JS after a
// deploy - so send no-store (browsers + Cloudflare always re-fetch the current shell).
$shell = fn () => response(view('app'))->header('Cache-Control', 'no-store, no-cache, must-revalidate');

Route::get('/', $shell);

// Public wallboard (GitHub #15): an unguessable per-map token serves the SPA in a read-only,
// no-login wallboard mode. Resolved here so a bad/disabled token 404s cleanly instead of
// loading a broken app; the map id + token are handed to the shell via meta tags. Declared
// before the SPA catch-all. The data still comes from the token-gated /api/public/wall endpoints.
Route::get('/wall/{token}', function (string $token) {
    $share = \App\Models\MapShare::where('token', $token)->where('enabled', true)->with('map')->first();
    abort_if($share === null || $share->map === null, 404);

    return response(view('app', ['wallToken' => $token, 'wallMapId' => $share->map->id]))
        ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
})->where('token', '[A-Za-z0-9]+')->name('wall.show');

// Public API reference (Scalar). Surfaced only on the sales/demo instance - a real monitoring
// instance 404s both the page and the spec. The spec lives outside public/ and is streamed here
// so it can't be fetched on a non-demo box. Declared before the SPA catch-all.
Route::prefix('api-docs')->group(function () {
    Route::get('/', function () {
        abort_unless((bool) config('mymate.demo.enabled'), 404);

        return response(view('api-docs'))->header('Cache-Control', 'no-store');
    })->name('api-docs');

    Route::get('/openapi.yaml', function () {
        abort_unless((bool) config('mymate.demo.enabled'), 404);
        $spec = resource_path('api-docs/openapi.yaml');
        abort_unless(is_file($spec), 404);

        return response(file_get_contents($spec), 200, [
            'Content-Type' => 'application/yaml; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    })->name('api-docs.spec');
});

// SPA catch-all: any non-/api path returns the React shell so client-side routing works.
Route::get('/{any}', $shell)->where('any', '^(?!api).*$');
