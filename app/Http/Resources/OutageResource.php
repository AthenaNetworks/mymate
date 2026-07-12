<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OutageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'device_id' => $this->device_id,
            'device_name' => $this->device?->name,
            'started_at' => $this->started_at,
            'ended_at' => $this->ended_at,
            'duration_s' => $this->duration_s,
            'ongoing' => $this->ended_at === null,
            'cause' => $this->cause,
        ];
    }
}
