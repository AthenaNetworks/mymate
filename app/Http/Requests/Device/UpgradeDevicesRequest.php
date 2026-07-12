<?php

namespace App\Http\Requests\Device;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Bulk firmware upgrade: a non-empty list of device ids to upgrade. The UI gates
 * this behind an explicit confirmation (it reboots gear).
 */
class UpgradeDevicesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null; // authenticated operators only
    }

    public function rules(): array
    {
        return [
            'device_ids' => ['required', 'array', 'min:1'],
            'device_ids.*' => ['integer', 'distinct', 'exists:devices,id'],
            // Ordered = upgrade downstream-first, waiting for each device to recover
            // before its parent (one BulkUpgradeJob). Unordered = one job per device.
            'ordered' => ['sometimes', 'boolean'],
        ];
    }
}
