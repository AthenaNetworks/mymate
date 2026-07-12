<?php

namespace App\Http\Resources;

use App\Models\DiscoveryCandidate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DiscoveryCandidate */
class DiscoveryCandidateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ip' => $this->ip,
            'status' => $this->status->value,
            'sysname' => $this->sysname,
            'detected_method' => $this->detected_method?->value,
            'matched_credential_id' => $this->matched_credential_id,
            'first_seen' => $this->first_seen,
            'last_seen' => $this->last_seen,
        ];
    }
}
