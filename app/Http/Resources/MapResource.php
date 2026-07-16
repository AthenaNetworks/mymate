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
            // Position when this map is placed as a node on its parent map (null = not placed).
            'node_x' => $this->node_x,
            'node_y' => $this->node_y,
            'device_count' => $this->positions_count ?? $this->whenCounted('positions'),
        ];
    }
}
