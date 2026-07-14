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

// SPA catch-all: any non-/api path returns the React shell so client-side routing works.
Route::get('/{any}', $shell)->where('any', '^(?!api).*$');
