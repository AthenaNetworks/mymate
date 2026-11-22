<?php

namespace App\Http\Resources;

use App\Models\LinkSuggestion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LinkSuggestion */
class LinkSuggestionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $end = fn ($device, $iface) => [
            'device_id' => $device?->id,
            'device_name' => $device?->alias ?: $device?->name,
            'interface_id' => $iface?->id,
            'interface_name' => $iface?->name,
        ];

        return [
            'id' => $this->id,
            'a' => $end($this->aDevice, $this->aInterface),
            'b' => $end($this->bDevice, $this->bInterface),
            'protocol' => $this->protocol,
            'status' => $this->status,
            'first_seen_at' => $this->first_seen_at?->toIso8601String(),
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
        ];
    }
}
