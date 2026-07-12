<?php

namespace App\Http\Controllers\Api;

use App\Actions\Alerts\SendAlert;
use App\Http\Controllers\Controller;
use App\Http\Requests\Alert\StoreAlertTransportRequest;
use App\Http\Requests\Alert\UpdateAlertTransportRequest;
use App\Http\Resources\AlertTransportResource;
use App\Models\AlertTransport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Alert transport CRUD. The config (email / webhook URL) is encrypted
 * + never returned; write-only on update (blank keeps the existing value).
 */
class AlertTransportController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return AlertTransportResource::collection(AlertTransport::orderBy('name')->get());
    }

    public function store(StoreAlertTransportRequest $request): JsonResponse
    {
        $data = $request->validated();
        $transport = AlertTransport::create([
            'name' => $data['name'],
            'type' => $data['type'],
            'enabled' => $data['enabled'] ?? true,
            'config' => $this->configFrom($data),
        ]);

        return (new AlertTransportResource($transport))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateAlertTransportRequest $request, AlertTransport $alertTransport): AlertTransportResource
    {
        $data = $request->validated();
        $attrs = [];
        if (isset($data['name'])) {
            $attrs['name'] = $data['name'];
        }
        if (isset($data['type'])) {
            $attrs['type'] = $data['type'];
        }
        if (array_key_exists('enabled', $data)) {
            $attrs['enabled'] = $data['enabled'];
        }
        // Rebuild config only if an address/URL was supplied (blank = keep existing).
        if (! empty($data['email'])) {
            $attrs['config'] = ['email' => $data['email']];
        } elseif (! empty($data['webhook_url'])) {
            $attrs['config'] = ['webhook_url' => $data['webhook_url']];
        }
        $alertTransport->update($attrs);

        return new AlertTransportResource($alertTransport);
    }

    public function destroy(AlertTransport $alertTransport): Response
    {
        $alertTransport->delete();

        return response()->noContent();
    }

    /** Send a test message through this transport (rate-limited at the route). */
    public function test(AlertTransport $transport, SendAlert $sender): JsonResponse
    {
        try {
            $sender->deliver($transport, 'My Mate test alert - this transport is working.');

            return response()->json(['ok' => true]);
        } catch (\Throwable) {
            return response()->json(['ok' => false, 'error' => 'Delivery failed.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function configFrom(array $data): array
    {
        return ($data['type'] ?? null) === 'email'
            ? ['email' => $data['email'] ?? null]
            : ['webhook_url' => $data['webhook_url'] ?? null];
    }
}
