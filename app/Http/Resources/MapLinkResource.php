<?php

namespace App\Http\Resources;

use App\Models\MapLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MapLink
 *
 * A manual, device-less link between two child-map nodes on a canvas (GitHub #9).
 */
class MapLinkResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'map_id' => $this->map_id,
            'a_map_id' => $this->a_map_id,
            'b_map_id' => $this->b_map_id,
            'a_handle' => $this->a_handle,
            'b_handle' => $this->b_handle,
            'media_type' => $this->media_type,
            'label' => $this->label,
        ];
    }
}
