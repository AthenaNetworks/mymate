<?php

namespace App\Http\Requests\Credential;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update a credential. Secrets are write-only: a blank field means
 * "keep the existing value" (the controller drops blanks before saving).
 */
class UpdateCredentialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null; // authenticated operators only
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'type' => ['sometimes', 'required', Rule::in(['snmp', 'routeros', 'ssh'])],
            'snmp_community' => ['nullable', 'string'],
            'snmp_version' => ['sometimes', Rule::in(['1', '2c', '3'])],
            // v3 USM. Secrets are write-only (blank = keep); the controller drops blanks, so no
            // required_if / min here - a length rule would reject the "keep existing" empty value.
            'snmp_sec_name' => ['nullable', 'string', 'max:255'],
            'snmp_sec_level' => ['nullable', Rule::in(['noAuthNoPriv', 'authNoPriv', 'authPriv'])],
            'snmp_auth_protocol' => ['nullable', Rule::in(['MD5', 'SHA', 'SHA-224', 'SHA-256', 'SHA-384', 'SHA-512'])],
            'snmp_auth_passphrase' => ['nullable', 'string'],
            'snmp_priv_protocol' => ['nullable', Rule::in(['DES', 'AES', 'AES-192', 'AES-256'])],
            'snmp_priv_passphrase' => ['nullable', 'string'],
            'username' => ['nullable', 'string'],
            'password' => ['nullable', 'string'],
            'private_key' => ['nullable', 'string'],
            'api_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
        ];
    }
}
