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
            'parent_device_id' => [
                'sometimes', 'nullable', 'integer', 'exists:devices,id',
                Rule::notIn([$this->route('device')?->id]), // a device can't be its own parent
            ],
        ];
    }
}
