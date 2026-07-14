<?php

namespace App\Http\Requests;

use App\Enums\DeviceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'scope' => ['nullable', 'array'],
            'scope.type' => ['nullable', Rule::in(['all', 'device_type', 'map', 'devices'])],
            'scope.device_type' => ['nullable', 'required_if:scope.type,device_type', Rule::enum(DeviceType::class)],
            'scope.map_id' => ['nullable', 'required_if:scope.type,map', 'integer', 'exists:maps,id'],
            'scope.device_ids' => ['nullable', 'required_if:scope.type,devices', 'array'],
            'scope.device_ids.*' => ['integer', 'exists:devices,id'],
        ];
    }
}
