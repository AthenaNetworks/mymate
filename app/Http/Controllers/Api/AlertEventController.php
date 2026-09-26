<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AlertEventResource;
use App\Models\AlertEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Recent fired/resolved alerts, newest first. */
class AlertEventController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        // ?status=firing lists only what's firing right now (the nav badge), so a long-firing alert
        // can't fall out of the 200 most recent rows.
        $status = $request->query('status');

        return AlertEventResource::collection(
            AlertEvent::with(['policy:id,name,condition', 'acknowledgedBy:id,name'])
                ->when(
                    $status === 'firing' || $status === 'resolved',
                    fn ($q) => $q->where('status', $status),
                    fn ($q) => $q->where('status', '!=', 'pending'), // hide breaches that haven't fired yet
                )
                ->latest('fired_at')
                ->limit(200)
                ->get(),
        );
    }

    /** Toggle acknowledgement: mark as handled (by the current operator), or clear it. */
    public function ack(Request $request, AlertEvent $alertEvent): AlertEventResource
    {
        $alertEvent->forceFill(
            $alertEvent->acknowledged_at === null
                ? ['acknowledged_at' => now(), 'acknowledged_by' => $request->user()?->id]
                : ['acknowledged_at' => null, 'acknowledged_by' => null]
        )->save();

        return new AlertEventResource($alertEvent->load(['policy:id,name,condition', 'acknowledgedBy:id,name']));
    }
}
