<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMaintenanceWindowRequest extends FormRequest
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
            'starts_at' => [$creating ? 'required' : 'sometimes', 'date'],
            'ends_at' => [$creating ? 'required' : 'sometimes', 'date', 'after:starts_at'],
            'enabled' => ['sometimes', 'boolean'],
            // Targeting - same bag as alert policies. null/all = fleet-wide.
            // Targeting, in either scope shape (shared with the other scoped features).
            ...ScopeRules::rules(),
        ];
    }
}
