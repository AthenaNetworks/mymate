<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A transport for the Alerts UI. The `config` (email / webhook URL) is encrypted +
 * `$hidden` on the model and is **never** returned here.
 */
class AlertTransportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type->value,
            'enabled' => $this->enabled,
        ];
    }
}
