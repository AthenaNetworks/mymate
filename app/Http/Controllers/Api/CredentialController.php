<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Credential\StoreCredentialRequest;
use App\Http\Requests\Credential\UpdateCredentialRequest;
use App\Http\Resources\CredentialResource;
use App\Models\Credential;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Credential management for the Settings screen. Secrets are
 * encrypted at rest (model casts) and never returned (model `$hidden` +
 * CredentialResource). Write-only on update: a blank secret keeps the existing one.
 */
class CredentialController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return CredentialResource::collection(Credential::withCount('devices')->orderBy('name')->get());
    }

    public function store(StoreCredentialRequest $request): JsonResponse
    {
        $credential = Credential::create($request->validated());

        return (new CredentialResource($credential->loadCount('devices')))
            ->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateCredentialRequest $request, Credential $credential): CredentialResource
    {
        $data = $request->validated();

        // Write-only secrets: a blank field means "keep the existing value".
        foreach (['snmp_community', 'username', 'password', 'private_key', 'snmp_auth_passphrase', 'snmp_priv_passphrase'] as $secret) {
            if (($data[$secret] ?? '') === '') {
                unset($data[$secret]);
            }
        }

        $credential->update($data);

        return new CredentialResource($credential->loadCount('devices'));
    }

    public function destroy(Credential $credential): Response
    {
        $credential->delete();

        return response()->noContent();
    }
}
