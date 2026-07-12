<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Credential for the Settings UI. Secrets are **never** returned -
 * only whether one is set (`has_secret`). The model also `$hidden`s the secrets.
 */
class CredentialResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'api_port' => $this->api_port,
            'has_secret' => $this->type === 'snmp' ? filled($this->snmp_community) : filled($this->password),
            'device_count' => $this->devices_count ?? 0,
        ];
    }
}
