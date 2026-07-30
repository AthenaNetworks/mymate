<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SiteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'kind' => $this->kind->value,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'address' => $this->address,
            'external_ref' => $this->external_ref,
            'note' => $this->note,
            // Present only when the caller asked for counts (the index does) - the map uses it
            // to size a site marker, and the list to show how much gear is at each location.
            'device_count' => $this->whenCounted('devices'),
            // Rolled up from the devices at this site, so the map can colour a whole tower red
            // without shipping every device down with it.
            'devices_down' => $this->when(isset($this->devices_down_count), fn () => (int) $this->devices_down_count),
        ];
    }
}
