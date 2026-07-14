<?php

namespace App\Http\Controllers;

use App\Models\RouterosPackage;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves a cached RouterOS package to a router (via /tool/fetch). Deliberately
 * unauthenticated - a router can't carry the operator's session - but gated by the
 * package's unguessable `token`. The payload is MikroTik's public firmware, not a secret.
 */
class RouterosPackageDownloadController extends Controller
{
    public function __invoke(string $token): BinaryFileResponse
    {
        $pkg = RouterosPackage::where('token', $token)->where('status', 'ready')->firstOrFail();

        abort_unless($pkg->path && Storage::disk('local')->exists($pkg->path), 404);

        return response()->file(Storage::disk('local')->path($pkg->path), [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.basename($pkg->path).'"',
        ]);
    }
}
