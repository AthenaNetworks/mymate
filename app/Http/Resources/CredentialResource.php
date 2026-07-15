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
        $snmpVersion = $this->snmp_version ?: '2c';
        $isV3 = $this->type === 'snmp' && $snmpVersion === '3';

        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'api_port' => $this->api_port,
            // Non-secret SNMPv3 params so the edit form can pre-populate; passphrases stay hidden.
            'snmp_version' => $this->type === 'snmp' ? $snmpVersion : null,
            'snmp_sec_name' => $this->snmp_sec_name,
            'snmp_sec_level' => $this->snmp_sec_level,
            'snmp_auth_protocol' => $this->snmp_auth_protocol,
            'snmp_priv_protocol' => $this->snmp_priv_protocol,
            'has_secret' => $this->type === 'snmp'
                ? ($isV3 ? filled($this->snmp_sec_name) : filled($this->snmp_community))
                : (filled($this->password) || filled($this->private_key)),
            'has_private_key' => filled($this->private_key),
            'device_count' => $this->devices_count ?? 0,
        ];
    }
}
