<?php

namespace App\Http\Requests\Alert;

use App\Enums\TransportType;
use App\Rules\SafeOutboundUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update a transport. Config fields (email / webhook_url) are write-only:
 * a blank field keeps the existing encrypted value.
 */
class UpdateAlertTransportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'type' => ['sometimes', 'required', Rule::enum(TransportType::class)],
            'enabled' => ['sometimes', 'boolean'],
            'email' => ['nullable', 'email'],
            // SafeOutboundUrl - see StoreAlertTransportRequest.
            'webhook_url' => ['nullable', 'url', new SafeOutboundUrl],
        ];
    }
}
