<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alert\StoreAlertPolicyRequest;
use App\Http\Requests\Alert\UpdateAlertPolicyRequest;
use App\Http\Resources\AlertPolicyResource;
use App\Models\AlertPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/** Alert policy CRUD. */
class AlertPolicyController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return AlertPolicyResource::collection(AlertPolicy::with('transports:id')->latest()->get());
    }

    public function store(StoreAlertPolicyRequest $request): JsonResponse
    {
        $data = $request->validated();
        $policy = AlertPolicy::create([
            'name' => $data['name'],
            'condition' => $data['condition'],
            'params' => $data['params'] ?? [],
            'scope' => $data['scope'] ?? null,
            'enabled' => $data['enabled'] ?? true,
        ]);
        $policy->transports()->sync($data['transport_ids'] ?? []);

        return (new AlertPolicyResource($policy->load('transports:id')))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateAlertPolicyRequest $request, AlertPolicy $alertPolicy): AlertPolicyResource
    {
        $data = $request->validated();
        $alertPolicy->update(array_intersect_key($data, array_flip(['name', 'condition', 'params', 'scope', 'enabled'])));

        if (array_key_exists('transport_ids', $data)) {
            $alertPolicy->transports()->sync($data['transport_ids'] ?? []);
        }

        return new AlertPolicyResource($alertPolicy->load('transports:id'));
    }

    public function destroy(AlertPolicy $alertPolicy): Response
    {
        $alertPolicy->delete();

        return response()->noContent();
    }
}
