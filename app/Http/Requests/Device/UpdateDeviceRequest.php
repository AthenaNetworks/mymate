<?php

namespace App\Http\Requests\Device;

use App\Enums\DeviceType;
use App\Enums\PollMethod;
use App\Rules\ManageableIp;
use App\Rules\NotADeviceDescendant;
use App\Support\DeviceIpScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            // Unique per poll scope (agent or central), checked in after() - see DeviceIpScope.
            'mgmt_ip' => ['sometimes', 'nullable', 'string', 'max:45', 'ip', new ManageableIp],
            'poll_method' => ['sometimes', 'required', Rule::enum(PollMethod::class)],
            // Enable/disable monitoring - false pauses throughput + metrics polling.
            'monitored' => ['sometimes', 'boolean'],
            'credential_id' => ['nullable', 'integer', 'exists:credentials,id'],
            'ssh_credential_id' => ['nullable', 'integer', 'exists:credentials,id'],
            'routeros_credential_id' => ['nullable', 'integer', 'exists:credentials,id'],
            'agent_id' => ['nullable', 'integer', 'exists:agents,id'],
            'map_x' => ['sometimes', 'numeric'],
            'map_y' => ['sometimes', 'numeric'],
            // Geographic position (geo overlay). Both or neither; setting them marks the source manual.
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            // Site assignment through the editor. Setting it marks the source manual so a
            // re-import or nearest-site pass never moves it (see UpdateDevice).
            'site_id' => ['sometimes', 'nullable', 'integer', 'exists:sites,id'],
            'device_type' => ['sometimes', Rule::enum(DeviceType::class)],
            // Icon override: a named glyph key + a hex colour (both nullable = auto).
            'icon' => ['sometimes', 'nullable', 'string', 'max:40'],
            'icon_color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'vendor' => ['sometimes', 'nullable', 'string', 'max:255'],
            'model' => ['sometimes', 'nullable', 'string', 'max:255'],
            // Latency quality thresholds (internet/upstream card), 0-65535ms. Ordering
            // (good <= bad) is enforced in the editor; the card's colour logic tolerates
            // either order regardless.
            'latency_good_ms' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:65535'],
            'latency_bad_ms' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:65535'],
            // The uplink this device hangs off - drives alert suppression, upgrade ordering,
            // geo inheritance and the tree layouts. NotADeviceDescendant covers both a device
            // parented to itself and one parented to its own downstream gear (a loop).
            'parent_device_id' => [
                'sometimes', 'nullable', 'integer', 'exists:devices,id',
                new NotADeviceDescendant($this->route('device')?->id),
            ],
        ];
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
