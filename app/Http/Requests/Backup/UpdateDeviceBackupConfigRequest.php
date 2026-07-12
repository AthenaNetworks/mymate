<?php

namespace App\Http\Requests\Backup;

use App\Support\RustedDrivers;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Per-device backup opt-in: whether this device is backed up and which
 * Rusted driver to use. Admin-only (route group). `backup_driver` must be one Rusted ships;
 * it may be null while backups are off, but turning them on without a resolvable driver is
 * rejected at the Action layer with a clear message.
 */
class UpdateDeviceBackupConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'backup_enabled' => ['required', 'boolean'],
            'backup_driver' => ['nullable', 'string', Rule::in(RustedDrivers::ALL)],
        ];
    }
}
