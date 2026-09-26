<?php

namespace App\Http\Requests\Device;

use App\Enums\DeviceType;
use App\Enums\PollMethod;
use App\Rules\ManageableIp;
use App\Support\DeviceIpScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            // Unique per poll scope (agent or central), checked in after() - see DeviceIpScope.
            'mgmt_ip' => ['required', 'string', 'max:45', 'ip', new ManageableIp],
            'ping_source' => ['nullable', 'string', 'max:45', 'ip'],
            'poll_method' => ['required', Rule::enum(PollMethod::class)],
            'credential_id' => ['nullable', 'integer', 'exists:credentials,id'],
            'ssh_credential_id' => ['nullable', 'integer', 'exists:credentials,id'],
            'routeros_credential_id' => ['nullable', 'integer', 'exists:credentials,id'],
            'agent_id' => ['nullable', 'integer', 'exists:agents,id'],
            'map_x' => ['nullable', 'numeric'],
            'map_y' => ['nullable', 'numeric'],
            'device_type' => ['sometimes', Rule::enum(DeviceType::class)],
            'parent_device_id' => ['nullable', 'integer', 'exists:devices,id'],
            // Off = monitored but placed on no map (default on: lands on the default map).
            'place_on_map' => ['sometimes', 'boolean'],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $agentId = $this->filled('agent_id') ? (int) $this->input('agent_id') : null;
            DeviceIpScope::check($validator, $this->input('mgmt_ip'), $agentId);
        }];
    }
}
