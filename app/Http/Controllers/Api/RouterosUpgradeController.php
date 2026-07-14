<?php

namespace App\Http\Controllers\Api;

use App\Actions\Upgrade\FetchRouterosPackage;
use App\Enums\PollMethod;
use App\Http\Controllers\Controller;
use App\Http\Resources\RouterosPackageResource;
use App\Jobs\FetchRouterosPackageJob;
use App\Models\Device;
use App\Models\RouterosPackage;
use App\Services\Upgrade\RouterosReleases;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * RouterOS upgrade catalog + package mirror control plane: the versions available per
 * channel, the CPU arches in the fleet, and the cached packages (list / fetch / delete).
 */
class RouterosUpgradeController extends Controller
{
    /** Catalog for the upgrade picker: channels, fleet arches, and cached packages. */
    public function catalog(RouterosReleases $releases): JsonResponse
    {
        $arches = Device::query()
            ->where('poll_method', PollMethod::RouterOs)
            ->whereNotNull('arch')
            ->distinct()
            ->pluck('arch')
            ->all();

        return response()->json([
            'data' => [
                'channels' => $releases->channels(),
                'arches' => RouterosReleases::ARCHES,
                'device_arches' => $arches,
                'retention_days' => (int) config('mymate.upgrade.package_retention_days', 90),
                'packages' => RouterosPackageResource::collection(RouterosPackage::orderByDesc('created_at')->get()),
            ],
        ]);
    }

    /** Start caching a package for a version + arch (or return an already-cached one). */
    public function fetch(Request $request, FetchRouterosPackage $fetch): JsonResponse
    {
        $data = $request->validate([
            'version' => ['required', 'string', 'regex:/^\d+\.\d+(\.\d+)?$/'],
            'arch' => ['required', Rule::in(RouterosReleases::ARCHES)],
            'channel' => ['nullable', 'string', 'max:32'],
        ]);

        $pkg = $fetch->ensureRow($data['version'], $data['arch'], $data['channel'] ?? null);
        if (! $pkg->isReady()) {
            $pkg->forceFill(['status' => 'pending', 'error' => null])->save();
            FetchRouterosPackageJob::dispatch($data['version'], $data['arch'], $data['channel'] ?? null);
        }

        return (new RouterosPackageResource($pkg))->response()->setStatusCode(Response::HTTP_ACCEPTED);
    }

    public function destroy(RouterosPackage $package, FetchRouterosPackage $fetch): Response
    {
        $fetch->delete($package);

        return response()->noContent();
    }
}
