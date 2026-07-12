<?php

namespace App\Http\Controllers\Api;

use App\Actions\Discovery\IgnoreCandidate;
use App\Actions\Discovery\PromoteCandidate;
use App\Enums\DiscoveryStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\DeviceResource;
use App\Http\Resources\DiscoveryCandidateResource;
use App\Models\DiscoveryCandidate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class DiscoveryCandidateController extends Controller
{
    /** The review queue. Optional `?status=new|approved|ignored` filter. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = DiscoveryCandidate::query()->latest('last_seen');

        $status = $request->query('status');
        if (is_string($status) && DiscoveryStatus::tryFrom($status) !== null) {
            $query->where('status', $status);
        }

        return DiscoveryCandidateResource::collection($query->get());
    }

    /** Approve -> create (or reuse) the device + kick discovery/poll. */
    public function approve(DiscoveryCandidate $candidate, PromoteCandidate $promote): JsonResponse
    {
        // Only identified hosts (SNMP/RouterOS) are promotable - there's nothing to poll
        // otherwise. Post-#7 these don't get queued, but legacy rows might still exist.
        abort_if(
            $candidate->detected_method === null,
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'This candidate was never identified via SNMP or RouterOS, so it cannot be promoted.'
        );

        $device = $promote($candidate);

        return (new DeviceResource($device))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /** Ignore -> dismiss (never re-offered on re-scan). */
    public function ignore(DiscoveryCandidate $candidate, IgnoreCandidate $ignore): DiscoveryCandidateResource
    {
        return new DiscoveryCandidateResource($ignore($candidate));
    }
}
