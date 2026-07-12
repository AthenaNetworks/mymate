<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Device\DiscoverDeviceRequest;
use App\Jobs\DiscoverInterfacesJob;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Trigger interface (re)discovery for one device on demand - so an
 * operator isn't stuck waiting for the ~10-min loop when a device shows no
 * interfaces (e.g. BDR1). Dispatches the same job the loop uses; we never poll
 * inside a web request, so the result lands asynchronously (refetch interfaces /
 * read `discovery_error` after).
 */
class DiscoverDeviceController extends Controller
{
    public function __invoke(DiscoverDeviceRequest $request, Device $device): JsonResponse
    {
        DiscoverInterfacesJob::dispatch($device->id);

        return response()->json(
            ['message' => "Interface discovery queued for {$device->name}."],
            Response::HTTP_ACCEPTED,
        );
    }
}
