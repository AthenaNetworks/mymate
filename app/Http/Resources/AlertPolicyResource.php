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
            // Only on the list: matched devices, a few names, and the names of listed devices.
            'coverage' => $this->when($this->resource->coverage !== null, fn () => [
                'matched' => $this->resource->coverage['matched'],
                'total' => $this->resource->coverage['total'],
                'sample' => $this->resource->coverage['sample'],
                // An object keyed by device id (JSON would turn an empty array into []).
                'device_names' => (object) $this->resource->coverage['device_names'],
            ]),
            'transport_ids' => $this->whenLoaded('transports', fn () => $this->transports->pluck('id')->all()),
        ];
    }
}
