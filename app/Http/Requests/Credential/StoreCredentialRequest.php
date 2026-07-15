<?php

namespace App\Http\Requests\Credential;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCredentialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null; // authenticated operators only
    }

    private function isV3(): bool
    {
        return $this->input('type') === 'snmp' && $this->input('snmp_version') === '3';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['snmp', 'routeros', 'ssh'])],
            // SNMP version: v1/v2c use a community, v3 uses the USM fields below.
            'snmp_version' => ['nullable', Rule::in(['1', '2c', '3'])],
            'snmp_community' => ['nullable', 'string', Rule::requiredIf(fn () => $this->input('type') === 'snmp' && $this->input('snmp_version') !== '3')],
            // SNMPv3 USM.
            'snmp_sec_name' => ['nullable', 'string', 'max:255', Rule::requiredIf(fn () => $this->isV3())],
            'snmp_sec_level' => ['nullable', Rule::in(['noAuthNoPriv', 'authNoPriv', 'authPriv'])],
            'snmp_auth_protocol' => ['nullable', Rule::in(['MD5', 'SHA', 'SHA-224', 'SHA-256', 'SHA-384', 'SHA-512'])],
            'snmp_auth_passphrase' => ['nullable', 'string', 'min:8', Rule::requiredIf(fn () => $this->isV3() && $this->input('snmp_sec_level') !== 'noAuthNoPriv')],
            'snmp_priv_protocol' => ['nullable', Rule::in(['DES', 'AES', 'AES-192', 'AES-256'])],
            'snmp_priv_passphrase' => ['nullable', 'string', 'min:8', Rule::requiredIf(fn () => $this->isV3() && $this->input('snmp_sec_level') === 'authPriv')],
            // RouterOS + SSH creds are username logins.
            'username' => ['nullable', 'string', Rule::requiredIf(fn () => in_array($this->input('type'), ['routeros', 'ssh'], true))],
            // Password: always for RouterOS; for SSH only when no private key is supplied
            // (an SSH credential can authenticate with a key instead of a password).
            'password' => ['nullable', 'string', Rule::requiredIf(
                fn () => $this->input('type') === 'routeros'
                    || ($this->input('type') === 'ssh' && ! $this->filled('private_key'))
            )],
            // SSH private key (PEM / OpenSSH). Stored encrypted, never returned.
            'private_key' => ['nullable', 'string'],
            'api_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
        ];
    }
}
