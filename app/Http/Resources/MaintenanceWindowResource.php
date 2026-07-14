<?php

namespace App\Http\Resources;

use App\Models\MaintenanceWindow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MaintenanceWindow */
class MaintenanceWindowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'scope' => $this->scope ?? ['type' => 'all'],
            'enabled' => $this->enabled,
            'active' => $this->isActive(),
        ];
    }
}
