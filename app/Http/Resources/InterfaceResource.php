<?php

namespace App\Http\Resources;

use App\Models\NetworkInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin NetworkInterface */
class InterfaceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'device_id' => $this->device_id,
            'if_index' => $this->if_index,
            'name' => $this->name,
            'description' => $this->description,
            'speed_mbps' => $this->speed_mbps, // read-only from SNMP
            'util_in' => $this->util_in,
            'util_out' => $this->util_out,
            'bps_in' => $this->bps_in,
            'bps_out' => $this->bps_out,
        ];
    }
}
