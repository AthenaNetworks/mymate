<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AlertPolicyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'condition' => $this->condition->value,
            'condition_label' => $this->condition->label(),
            'params' => $this->params ?? [],
            'scope' => $this->scope ?? ['type' => 'all'], // targeting
            'enabled' => $this->enabled,
            'transport_ids' => $this->whenLoaded('transports', fn () => $this->transports->pluck('id')->all()),
        ];
    }
}
