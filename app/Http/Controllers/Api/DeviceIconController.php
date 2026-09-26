<?php

namespace App\Http\Controllers\Api;

use App\Actions\Devices\FetchMikrotikIcon;
use App\Http\Controllers\Controller;
use App\Jobs\FetchDeviceIconJob;
use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves a MikroTik device's cached product image. On a miss it dispatches a one-off fetch
 * (deduped + negative-cached to avoid re-dispatch storms) and 404s for now - the frontend
 * shows a drawn fallback icon until the image lands, then picks it up on the next load.
 * Only MikroTik is handled here (its CDN needs a lookup); other vendors use drawn icons.
 */
class DeviceIconController extends Controller
{
    public function show(Device $device, FetchMikrotikIcon $icons): BinaryFileResponse|Response
    {
        $vendor = mb_strtolower((string) $device->vendor);
        $model = (string) $device->model;

        if (! str_contains($vendor, 'mikrotik') || $model === '') {
            abort(404);
        }

        $path = $icons->cachedPath($model);
        if ($path === null) {
            // Dispatch the fetch at most once per model per window; don't re-hit MikroTik on
            // every render of an unresolvable model.
            $missKey = 'device-icon:seen:'.FetchMikrotikIcon::slug($model);
            if (Cache::add($missKey, 1, now()->addMinutes(30))) {
                FetchDeviceIconJob::dispatch($model);
            }
            abort(404);
        }

        return response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'public, max-age=604800, immutable',
        ]);
    }

    /**
     * The same photo keyed by model rather than device, so every node of one model on a map shares
     * one URL and one browser-cached image instead of a request per device (500 CRS326s used to
     * mean 500 requests). Only a model some visible MikroTik actually has is served or fetched, so
     * this can't be used to queue lookups for made-up models.
     */
    public function byModel(Request $request, FetchMikrotikIcon $icons): BinaryFileResponse|Response
    {
        $model = (string) $request->query('model', '');
        abort_if($model === '' || mb_strlen($model) > 100, 404);

        $device = Device::where('model', $model)->where('vendor', 'ilike', '%mikrotik%')->first(['id', 'vendor', 'model']);
        abort_if($device === null, 404);

        return $this->show($device, $icons);
    }
}
