<?php

namespace App\Http\Resources;

use App\Models\Link;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Link
 *
 * Carries both interface ids + each end's current util/speed so the map can render
 * coloured edges immediately on load (no flash of grey before the first Reverb frame).
 */
class LinkResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'a_device_id' => $this->a_device_id,
            'a_interface_id' => $this->a_interface_id,
            'a_handle' => $this->a_handle,
            'b_device_id' => $this->b_device_id,
            'b_interface_id' => $this->b_interface_id,
            'b_handle' => $this->b_handle,
            'a_interface' => new InterfaceResource($this->whenLoaded('aInterface')),
            'b_interface' => new InterfaceResource($this->whenLoaded('bInterface')),
            // Per-link bandwidth override + the resolved effective speed per direction
            // (override, else the slower of the two ends). The map edge divides live bps
            // by these to colour by link load.
            'bw_ab_mbps' => $this->bw_ab_mbps,
            'bw_ba_mbps' => $this->bw_ba_mbps,
            'eff_ab_mbps' => $this->effAbMbps(),
            'eff_ba_mbps' => $this->effBaMbps(),
        ];
    }
}
