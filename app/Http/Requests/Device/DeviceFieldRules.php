<?php

namespace App\Http\Requests\Device;

use App\Enums\DeviceType;
use App\Enums\PollMethod;
use App\Models\ImageAsset;
use App\Rules\ManageableIp;
use App\Rules\NotADeviceDescendant;
use Illuminate\Validation\Rule;

/**
 * The per-field rules for editing a device, shared by the single edit (UpdateDeviceRequest) and
 * the bulk edit (BulkUpdateDevicesRequest) so the two can't drift apart. Checks that depend on
 * the device's other values (IP scope, an IP for a polled device) live in each request's after().
 */
class DeviceFieldRules
{
    /**
     * The fields a bulk edit may set. Everything else is per-device by nature (name, alias, IP,
     * map position) or read from the device itself (vendor, model).
     */
    public const BULK_FIELDS = [
        'poll_method', 'monitored', 'device_type', 'credential_id', 'ssh_credential_id', 'routeros_credential_id',
        'agent_id', 'ping_source', 'parent_device_id', 'latitude', 'longitude', 'icon', 'icon_color',
        'latency_good_ms', 'latency_bad_ms',
    ];

    /**
     * @param  string  $prefix  prepended to every key (and to fields named inside a rule), e.g. "changes."
     * @param  int|null  $deviceId  the device being edited, for the parent-loop check; null skips it
     * @return array<string, array<int, mixed>>
     */
    public static function rules(string $prefix = '', ?int $deviceId = null): array
    {
        $rules = [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            // Friendly name shown on the map card instead of the hostname (GitHub #53).
            'alias' => ['sometimes', 'nullable', 'string', 'max:100'],
            // Unique per poll scope (agent or central), checked in after() - see DeviceIpScope.
            'mgmt_ip' => ['sometimes', 'nullable', 'string', 'max:45', 'ip', new ManageableIp],
            // Ping FROM this local address (fping -S / the agent's bound socket). Null = global default.
            'ping_source' => ['sometimes', 'nullable', 'string', 'max:45', 'ip'],
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
            // No `sometimes`: it would skip the missing half, and its required_with with it.
            'latitude' => ['nullable', 'numeric', 'between:-90,90', "required_with:{$prefix}longitude"],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', "required_with:{$prefix}latitude"],
            // Site assignment through the editor. Setting it marks the source manual so a
            // re-import or nearest-site pass never moves it (see UpdateDevice).
            'site_id' => ['sometimes', 'nullable', 'integer', 'exists:sites,id'],
            'device_type' => ['sometimes', Rule::enum(DeviceType::class)],
            // Icon override: a named glyph key + a hex colour (both nullable = auto).
            'icon' => ['sometimes', 'nullable', 'string', 'max:40', function (string $attr, mixed $value, \Closure $fail): void {
                // "img:<id>" points at an image in the library (GitHub #53) - it has to be there.
                if (is_string($value) && str_starts_with($value, ImageAsset::ICON_PREFIX)
                    && ! ImageAsset::whereKey((int) substr($value, strlen(ImageAsset::ICON_PREFIX)))->exists()) {
                    $fail('That image is no longer in the library.');
                }
            }],
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
                new NotADeviceDescendant($deviceId),
            ],
        ];

        if ($prefix === '') {
            return $rules;
        }

        $out = [];
        foreach ($rules as $key => $r) {
            $out[$prefix.$key] = $r;
        }

        return $out;
    }
}
