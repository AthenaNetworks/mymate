<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MapResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'parent_map_id' => $this->parent_map_id,
            'is_default' => $this->is_default,
            'position' => $this->position,
            'device_count' => $this->positions_count ?? $this->whenCounted('positions'),
        ];
    }
}
