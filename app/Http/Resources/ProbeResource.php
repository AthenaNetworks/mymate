<?php

namespace App\Http\Resources;

use App\Models\Probe;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Probe */
class ProbeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'device_id' => $this->device_id,
            'name' => $this->name,
            'kind' => $this->kind->value,
            'enabled' => (bool) $this->enabled,
            'interval_s' => $this->interval_s,
            'timeout_ms' => $this->timeout_ms,
            'fail_threshold' => $this->fail_threshold,
            'config' => $this->config ?? [],
            // Live result.
            'status' => $this->status?->value ?? 'unknown',
            'latency_ms' => $this->latency_ms,
            'message' => $this->message,
            'cert_expires_at' => $this->cert_expires_at,
            'checked_at' => $this->checked_at,
        ];
    }
}
