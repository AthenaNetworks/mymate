<?php

namespace App\Http\Controllers\Api;

use App\Actions\Discovery\CreateSubnet;
use App\Actions\Discovery\DeleteSubnet;
use App\Actions\Discovery\UpdateSubnet;
use App\Http\Controllers\Controller;
use App\Http\Requests\Subnet\StoreSubnetRequest;
use App\Http\Requests\Subnet\UpdateSubnetRequest;
use App\Http\Resources\SubnetResource;
use App\Jobs\ScanSubnetJob;
use App\Models\Subnet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class SubnetController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return SubnetResource::collection(Subnet::orderBy('cidr')->get());
    }

    public function store(StoreSubnetRequest $request, CreateSubnet $createSubnet): JsonResponse
    {
        $subnet = $createSubnet($request->validated());

        return (new SubnetResource($subnet))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateSubnetRequest $request, Subnet $subnet, UpdateSubnet $updateSubnet): SubnetResource
    {
        return new SubnetResource($updateSubnet($subnet, $request->validated()));
    }

    public function destroy(Subnet $subnet, DeleteSubnet $deleteSubnet): Response
    {
        $deleteSubnet($subnet);

        return response()->noContent();
    }

    /**
     * Trigger an on-demand scan of this subnet. Returns 202 - the sweep runs in the
     * background and the candidate queue surfaces results as they're found (a disabled
     * subnet is a no-op).
     *
     * A central subnet goes straight onto the isolated `scan` queue. An agent-assigned
     * subnet can't be scanned from here (it lives inside a remote management network), so
     * we just mark it due - its agent picks it up on the next dispatch tick.
     */
    public function scan(Subnet $subnet): JsonResponse
    {
        if ($subnet->agent_id !== null) {
            $subnet->update(['last_scanned_at' => null]);
        } else {
            ScanSubnetJob::dispatch($subnet->id);
        }

        return (new SubnetResource($subnet->fresh()))->response()->setStatusCode(Response::HTTP_ACCEPTED);
    }
}
