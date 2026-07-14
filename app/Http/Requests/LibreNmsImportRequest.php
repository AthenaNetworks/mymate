<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Connection details for a LibreNMS import - MySQL (host/db/user/pass) or API (url/token) -
 * plus the import options (credential mapping, whether to bring credentials + maps).
 */
class LibreNmsImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'driver' => ['required', Rule::in(['mysql', 'api'])],

            // MySQL.
            'host' => ['nullable', 'required_if:driver,mysql', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'database' => ['nullable', 'required_if:driver,mysql', 'string', 'max:128'],
            'username' => ['nullable', 'required_if:driver,mysql', 'string', 'max:128'],
            'password' => ['nullable', 'string', 'max:255'],

            // API.
            'base_url' => ['nullable', 'required_if:driver,api', 'url', 'max:255'],
            'token' => ['nullable', 'required_if:driver,api', 'string', 'max:255'],

            // Import options (import endpoint only).
            'import_credentials' => ['sometimes', 'boolean'],
            'import_maps' => ['sometimes', 'boolean'],
            'include_disabled' => ['sometimes', 'boolean'],
            'credential_id' => ['nullable', 'integer', 'exists:credentials,id'],
            'only' => ['sometimes', 'array'],
            'only.*' => ['string'],
            'credential_map' => ['sometimes', 'array'],
            'credential_map.*' => ['integer', 'exists:credentials,id'],
        ];
    }
}
