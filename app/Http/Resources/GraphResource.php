<?php

namespace App\Http\Resources;

use App\Models\Graph;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Graph */
class GraphResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'config' => $this->config ?? ['metric' => 'rate', 'series' => [], 'show_total' => false],
        ];
    }
}
