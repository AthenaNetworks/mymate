<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Remote agent for the Agents UI. The bearer token is never returned (only its hash is
 * stored, and even that is `$hidden`); the plaintext is surfaced once, by the store
 * endpoint, at enrolment time.
 */
class AgentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status->value,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'version' => $this->version,
            'platform' => $this->platform,
            'device_count' => $this->devices_count ?? 0,
            'subnet_count' => $this->subnets_count ?? 0,
        ];
    }
}
