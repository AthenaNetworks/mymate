<?php

namespace App\Http\Requests;

use App\Enums\ProbeKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate a service probe (GitHub #19). Shared by store (POST) and update (PUT); on update most
 * fields are optional. The config bag is validated per kind - a URL for HTTP, a port for TCP.
 */
class StoreProbeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'kind' => [$creating ? 'required' : 'sometimes', Rule::enum(ProbeKind::class)],
            'enabled' => ['sometimes', 'boolean'],
            'interval_s' => ['sometimes', 'integer', 'min:5', 'max:86400'],
            'timeout_ms' => ['sometimes', 'integer', 'min:500', 'max:60000'],
            'fail_threshold' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'config' => ['sometimes', 'array'],

            // HTTP probe config.
            'config.url' => ['required_if:kind,http', 'nullable', 'url', 'max:2048'],
            'config.method' => ['nullable', Rule::in(['GET', 'HEAD', 'POST'])],
            'config.expect_status' => ['nullable', 'string', 'max:64'],
            'config.expect_body' => ['nullable', 'string', 'max:255'],
            'config.verify_tls' => ['nullable', 'boolean'],

            // TCP probe config. Host defaults to the device's management address when omitted.
            'config.host' => ['nullable', 'string', 'max:255'],
            'config.port' => ['required_if:kind,tcp', 'nullable', 'integer', 'min:1', 'max:65535'],
        ];
    }
}
