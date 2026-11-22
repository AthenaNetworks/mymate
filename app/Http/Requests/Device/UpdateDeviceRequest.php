<?php

namespace App\Http\Requests\Device;

use App\Enums\PollMethod;
use App\Support\DeviceIpScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null; // authenticated operators only
    }

    public function rules(): array
    {
        return DeviceFieldRules::rules('', $this->route('device')?->id);
    }

    /**
     * Check the device's *effective* IP + agent after this edit, so changing only the agent (moving
     * the device onto an agent that already polls that IP) is caught too, not just an IP change.
     *
     * @return list<callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $device = $this->route('device');
            if ($device === null) {
                return;
            }
            $ip = $this->has('mgmt_ip') ? $this->input('mgmt_ip') : $device->mgmt_ip;
            $agentId = $this->has('agent_id')
                ? ($this->filled('agent_id') ? (int) $this->input('agent_id') : null)
                : $device->agent_id;
            // Only a ping-only device may drop its IP (becoming a static map object); SNMP and
            // RouterOS polling have nothing to talk to without one.
            $method = $this->has('poll_method') ? (string) $this->input('poll_method') : $device->poll_method->value;
            if (($ip === null || $ip === '') && $method !== PollMethod::None->value && ! $validator->errors()->has('mgmt_ip')) {
                $validator->errors()->add('mgmt_ip', 'A device polled over SNMP or RouterOS needs a management IP. Only a ping-only device can be a static object with no IP.');

                return;
            }
            DeviceIpScope::check($validator, $ip, $agentId, $device->id);
        }];
    }
}
