<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OutageResource;
use App\Models\Outage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Outage timeline. Newest-first, capped. Optional filters:
 * `?device_id=` (one device) and `?state=open|closed`.
 */
class OutageController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Outage::query()->with('device')->latest('started_at');

        $deviceId = $request->integer('device_id');
        if ($deviceId > 0) {
            $query->where('device_id', $deviceId);
        }

        $state = $request->query('state');
        if ($state === 'open') {
            $query->whereNull('ended_at');
        } elseif ($state === 'closed') {
            $query->whereNotNull('ended_at');
        }

        return OutageResource::collection($query->limit(500)->get());
    }
}
