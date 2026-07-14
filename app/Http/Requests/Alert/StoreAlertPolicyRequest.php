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
            // Threshold: % (util / cpu / mem / loss), °C (temp) or ms (latency). One wide
            // ceiling covers them all - an out-of-range value for a given metric just never fires.
            'params.threshold' => ['nullable', 'numeric', 'min:1', 'max:10000'],
            // Which device metric high_metric watches.
            'params.metric' => ['nullable', Rule::in(['cpu', 'mem', 'temp', 'latency', 'loss'])],
            // Sustained-duration gate (high_util / high_metric / device_down), in minutes. 0 = instant.
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
