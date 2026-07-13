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

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['snmp', 'routeros', 'ssh'])],
            'snmp_community' => ['nullable', 'string', 'required_if:type,snmp'],
            // RouterOS + SSH creds are username/password logins.
            'username' => ['nullable', 'string', Rule::requiredIf(fn () => in_array($this->input('type'), ['routeros', 'ssh'], true))],
            'password' => ['nullable', 'string', Rule::requiredIf(fn () => in_array($this->input('type'), ['routeros', 'ssh'], true))],
            'api_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
        ];
    }
}
