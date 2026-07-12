<?php

namespace App\Http\Resources;

use App\Models\Subnet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Subnet */
class SubnetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cidr' => $this->cidr,
            'label' => $this->label,
            'enabled' => $this->enabled,
            'scan_interval_s' => $this->scan_interval_s,
            'agent_id' => $this->agent_id,
            'last_scanned_at' => $this->last_scanned_at,
        ];
    }
}
