<?php

namespace App\Http\Requests\Device;

use App\Enums\DeviceType;
use App\Enums\PollMethod;
use App\Rules\ManageableIp;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null; // authenticated operators only
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'mgmt_ip' => ['sometimes', 'required', 'string', 'max:45', 'ip', Rule::unique('devices', 'mgmt_ip')->ignore($this->route('device')?->id), new ManageableIp],
            'poll_method' => ['sometimes', 'required', Rule::enum(PollMethod::class)],
            // Enable/disable monitoring - false pauses throughput + metrics polling.
            'monitored' => ['sometimes', 'boolean'],
            'credential_id' => ['nullable', 'integer', 'exists:credentials,id'],
            'ssh_credential_id' => ['nullable', 'integer', 'exists:credentials,id'],
            'agent_id' => ['nullable', 'integer', 'exists:agents,id'],
            'map_x' => ['sometimes', 'numeric'],
            'map_y' => ['sometimes', 'numeric'],
            'device_type' => ['sometimes', Rule::enum(DeviceType::class)],
            'vendor' => ['sometimes', 'nullable', 'string', 'max:255'],
            'model' => ['sometimes', 'nullable', 'string', 'max:255'],
            'parent_device_id' => [
                'sometimes', 'nullable', 'integer', 'exists:devices,id',
                Rule::notIn([$this->route('device')?->id]), // a device can't be its own parent
            ],
        ];
    }
}
