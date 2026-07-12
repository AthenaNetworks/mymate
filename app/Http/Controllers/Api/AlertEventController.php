<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AlertEventResource;
use App\Models\AlertEvent;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Recent fired/resolved alerts, newest first. */
class AlertEventController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return AlertEventResource::collection(
            AlertEvent::with('policy:id,name,condition')
                ->where('status', '!=', 'pending') // hide breaches that haven't fired yet
                ->latest('fired_at')
                ->limit(200)
                ->get(),
        );
    }
}
