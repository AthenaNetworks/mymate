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
            'type' => ['sometimes', 'required', Rule::in(['snmp', 'routeros'])],
            'snmp_community' => ['nullable', 'string'],
            'username' => ['nullable', 'string'],
            'password' => ['nullable', 'string'],
            'api_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
        ];
    }
}
