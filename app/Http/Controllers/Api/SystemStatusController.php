<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\SystemStatus;
use Illuminate\Http\JsonResponse;

/**
 * Authenticated system-status snapshot for the Settings panel: database, Redis,
 * workers, the polling loop, WebSockets and the backup engine. Best-effort - always
 * 200 with per-check status, so the UI can render a health board.
 */
class SystemStatusController extends Controller
{
    public function __invoke(SystemStatus $status): JsonResponse
    {
        return response()->json(['data' => $status->check()]);
    }
}
