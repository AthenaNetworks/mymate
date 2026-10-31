<?php

namespace App\Http\Requests\Cmr;

use Illuminate\Foundation\Http\FormRequest;

/**
 * CMR controller connection config. Admin-only (route middleware). The token is write-only
 * (blank/omitted = keep). As with the Rusted URL, the API URL is loopback infra, so it's checked
 * as a well-formed http(s) URL only, not through the SSRF guard.
 */
class UpdateCmrSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'api_url' => ['required', 'string', 'max:255', 'url:http,https'],
            'api_token' => ['nullable', 'string', 'max:1024'],
            // What devices are told to connect to: a host name or an IP, no scheme or port.
            'public_host' => ['nullable', 'string', 'max:253', 'regex:/^[A-Za-z0-9.:\-\[\]]+$/'],
        ];
    }
}
