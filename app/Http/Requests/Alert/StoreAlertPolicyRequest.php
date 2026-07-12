<?php

namespace App\Http\Requests\Alert;

use App\Enums\AlertCondition;
use App\Enums\DeviceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAlertPolicyRequest extends FormRequest
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
            'condition' => ['required', Rule::enum(AlertCondition::class)],
            'params' => ['nullable', 'array'],
            'params.threshold' => ['nullable', 'numeric', 'min:1', 'max:100'],
            // Sustained-duration gate for high_util, in minutes. 0 = instant.
            'params.duration_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            // Dependency-aware suppression for device_down. Default true.
            'params.suppress_dependent' => ['nullable', 'boolean'],
            // Targeting - limit the policy to a device subset. null/all = fleet-wide.
            'scope' => ['nullable', 'array'],
            'scope.type' => ['nullable', Rule::in(['all', 'device_type', 'map', 'devices'])],
            'scope.device_type' => ['nullable', 'required_if:scope.type,device_type', Rule::enum(DeviceType::class)],
            'scope.map_id' => ['nullable', 'required_if:scope.type,map', 'integer', 'exists:maps,id'],
            'scope.device_ids' => ['nullable', 'required_if:scope.type,devices', 'array'],
            'scope.device_ids.*' => ['integer', 'exists:devices,id'],
            'enabled' => ['sometimes', 'boolean'],
            'transport_ids' => ['nullable', 'array'],
            'transport_ids.*' => ['integer', 'exists:alert_transports,id'],
        ];
    }
}
