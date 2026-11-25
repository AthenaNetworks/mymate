<?php

namespace App\Http\Requests\Alert;

use App\Enums\AlertCondition;
use App\Http\Requests\ScopeRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAlertPolicyRequest extends FormRequest
{
    use ValidatesInterfaceTargeting;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'condition' => ['sometimes', 'required', Rule::enum(AlertCondition::class)],
            'params' => ['nullable', 'array'],
            // Threshold: % (util / cpu / mem / loss), °C (temp) or ms (latency). One wide
            // ceiling covers them all - an out-of-range value for a given metric just never fires.
            'params.threshold' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            // Which device metric high_metric watches.
            'params.metric' => ['nullable', Rule::in(['cpu', 'mem', 'temp', 'latency', 'loss'])],
            // Sustained-duration gate, honoured by every condition, in minutes. 0 = instant.
            'params.duration_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            // Dependency-aware suppression for device_down. Default true.
            'params.suppress_dependent' => ['nullable', 'boolean'],
            // Which interfaces interface_down / per-interface low_throughput watch.
            ...$this->interfaceTargetingRules(),
            // optical_power: which power, which side of the line, and the line itself in dBm.
            'params.optical' => ['nullable', Rule::in(['rx', 'tx'])],
            'params.bound' => ['nullable', Rule::in(['below', 'above'])],
            'params.dbm' => ['nullable', 'numeric', 'between:-60,30'],
            // Targeting - limit the policy to a device subset. null/all = fleet-wide.
            // Targeting, in either scope shape (shared with the other scoped features).
            ...ScopeRules::rules(),
            'enabled' => ['sometimes', 'boolean'],
            'transport_ids' => ['sometimes', 'array'],
            'transport_ids.*' => ['integer', 'exists:alert_transports,id'],
        ];
    }
}
