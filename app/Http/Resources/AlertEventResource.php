<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AlertEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'policy_id' => $this->alert_policy_id,
            'policy_name' => $this->policy?->name,
            'condition' => $this->policy?->condition?->value,
            'status' => $this->status,
            'message' => $this->message,
            'delivered' => $this->delivered,
            'fired_at' => $this->fired_at,
            'resolved_at' => $this->resolved_at,
        ];
    }
}
