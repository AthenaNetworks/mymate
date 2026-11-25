<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMaintenanceWindowRequest;
use App\Http\Resources\MaintenanceWindowResource;
use App\Models\MaintenanceWindow;
use App\Support\DeviceScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/** Maintenance window CRUD - scheduled spans that suppress alerts for the in-scope devices. */
class MaintenanceWindowController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return MaintenanceWindowResource::collection(
            MaintenanceWindow::orderByDesc('starts_at')->limit(100)->get()
        );
    }

    public function store(StoreMaintenanceWindowRequest $request): JsonResponse
    {
        $window = MaintenanceWindow::create(self::withScope($request->validated(), $request) + ['created_by' => $request->user()?->id]);

        return (new MaintenanceWindowResource($window))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(StoreMaintenanceWindowRequest $request, MaintenanceWindow $maintenanceWindow): MaintenanceWindowResource
    {
        $maintenanceWindow->update(self::withScope($request->validated(), $request));

        return new MaintenanceWindowResource($maintenanceWindow);
    }

    public function destroy(MaintenanceWindow $maintenanceWindow): Response
    {
        $maintenanceWindow->delete();

        return response()->noContent();
    }

    /**
     * Store the scope cleaned (known fields only), in whichever shape it came. Taken from the
     * request rather than validated(), which drops a scope whose sides are empty arrays.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function withScope(array $data, Request $request): array
    {
        if ($request->exists('scope')) {
            $data['scope'] = DeviceScope::normalize(DeviceScope::fromRequest($request));
        }

        return $data;
    }
}
