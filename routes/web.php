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

// SPA catch-all: any non-/api path returns the React shell so client-side routing works.
Route::get('/{any}', $shell)->where('any', '^(?!api).*$');
