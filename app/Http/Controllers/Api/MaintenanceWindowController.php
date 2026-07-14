<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMaintenanceWindowRequest;
use App\Http\Resources\MaintenanceWindowResource;
use App\Models\MaintenanceWindow;
use Illuminate\Http\JsonResponse;
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
        $window = MaintenanceWindow::create($request->validated() + ['created_by' => $request->user()?->id]);

        return (new MaintenanceWindowResource($window))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(StoreMaintenanceWindowRequest $request, MaintenanceWindow $maintenanceWindow): MaintenanceWindowResource
    {
        $maintenanceWindow->update($request->validated());

        return new MaintenanceWindowResource($maintenanceWindow);
    }

    public function destroy(MaintenanceWindow $maintenanceWindow): Response
    {
        $maintenanceWindow->delete();

        return response()->noContent();
    }
}
