<?php

namespace App\Http\Requests\Device;

use App\Enums\DeviceType;
use App\Enums\PollMethod;
use App\Rules\ManageableIp;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null; // authenticated operators only
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'mgmt_ip' => ['required', 'string', 'max:45', 'ip', 'unique:devices,mgmt_ip', new ManageableIp],
            'poll_method' => ['required', Rule::enum(PollMethod::class)],
            'credential_id' => ['nullable', 'integer', 'exists:credentials,id'],
            'ssh_credential_id' => ['nullable', 'integer', 'exists:credentials,id'],
            'agent_id' => ['nullable', 'integer', 'exists:agents,id'],
            'map_x' => ['nullable', 'numeric'],
            'map_y' => ['nullable', 'numeric'],
            'device_type' => ['sometimes', Rule::enum(DeviceType::class)],
            'parent_device_id' => ['nullable', 'integer', 'exists:devices,id'],
        ];
    }
}
