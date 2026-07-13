<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\UpdateChecker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UpdateCheckController extends Controller
{
    /**
     * Current version + whether a newer release is available. Cached; pass ?fresh=1 to
     * force a re-check against GitHub (rate-limited on the route).
     */
    public function __invoke(Request $request, UpdateChecker $checker): JsonResponse
    {
        return response()->json(['data' => $checker->check($request->boolean('fresh'))]);
    }
}
