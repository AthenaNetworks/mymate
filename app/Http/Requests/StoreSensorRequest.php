<?php

namespace App\Http\Requests;

use App\Models\Sensor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSensorRequest extends FormRequest
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
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            // A numeric SNMP OID, optionally dotted-leading. GET reads it as a scalar; walk
            // reads it as a table base and reduces the rows by `agg`.
            'oid' => [$creating ? 'required' : 'sometimes', 'string', 'regex:/^\.?\d+(\.\d+)*$/'],
            'mode' => ['sometimes', Rule::in(Sensor::MODES)],
            'agg' => ['nullable', 'required_if:mode,walk', Rule::in(Sensor::AGGS)],
            'unit' => ['nullable', 'string', 'max:16'],
            'divisor' => ['nullable', 'numeric', 'not_in:0'],
            'enabled' => ['sometimes', 'boolean'],
            'on_face' => ['sometimes', 'boolean'],
            // Targeting - same bag as alert policies. null/all = fleet-wide.
            // Targeting, in either scope shape (shared with the other scoped features).
            ...ScopeRules::rules(),
        ];
    }

    public function messages(): array
    {
        return ['oid.regex' => 'The OID must be numeric (digits and dots), e.g. 1.3.6.1.2.1.2.2.1.14.1'];
    }
}
