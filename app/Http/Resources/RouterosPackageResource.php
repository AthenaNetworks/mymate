<?php

namespace App\Http\Resources;

use App\Models\RouterosPackage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RouterosPackage */
class RouterosPackageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version,
            'arch' => $this->arch,
            'channel' => $this->channel,
            'status' => $this->status,
            'size_bytes' => $this->size_bytes,
            'error' => $this->error,
            'fetched_at' => $this->fetched_at?->toIso8601String(),
            // The router-facing serving URL (unauthenticated, token-gated). Absolute so a
            // router on the management network can fetch it from us.
            'download_url' => $this->status === 'ready' ? url("/rospkg/{$this->token}") : null,
        ];
    }
}
