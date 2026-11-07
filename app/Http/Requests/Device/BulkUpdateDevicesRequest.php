<?php

namespace App\Http\Requests\Device;

use App\Enums\PollMethod;
use App\Models\Credential;
use App\Models\Device;
use App\Rules\NotADeviceDescendant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Validator;

/**
 * One set of changes applied to many devices (Devices list + map multi-select). All-or-nothing:
 * every device is checked against the changes before anything is written, and a failure on any
 * one rejects the whole request, naming each device that's in the way under `devices.<id>`.
 *
 * Field rules come from DeviceFieldRules, shared with the single edit. The checks that depend on
 * a device's own values (an IP for a polled device, one IP per poll scope, no parent loops, a
 * credential that suits the poll method) are run here for every device in the batch.
 */
class BulkUpdateDevicesRequest extends FormRequest
{
    /** Enough for any real selection; keeps one request from rewriting an unbounded fleet. */
    public const MAX_DEVICES = 5000;

    /** Errors listed per device before the rest are summed up, so a 5000-device miss stays readable. */
    private const MAX_DEVICE_ERRORS = 50;

    /** @var Collection<int, Device>|null */
    private ?Collection $devices = null;

    public function authorize(): bool
    {
        return $this->user() !== null; // admin-only through RestrictWritesToAdmins
    }

    public function rules(): array
    {
        // Prefixed so rules that name another field (latitude <-> longitude) look inside `changes`.
        $fields = Arr::only(
            DeviceFieldRules::rules('changes.'),
            array_map(fn (string $f) => "changes.{$f}", DeviceFieldRules::BULK_FIELDS),
        );
        // The parent-loop rule is per device; the batch version runs in after() instead.
        $fields['changes.parent_device_id'] = ['sometimes', 'nullable', 'integer', 'exists:devices,id'];

        return [
            'device_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_DEVICES],
            'device_ids.*' => ['integer', 'distinct'],
            'changes' => ['required', 'array'],
            ...$fields,
        ];
    }

    /** The devices being changed, loaded once by the checks below (visibility-scoped). */
    public function devices(): Collection
    {
        return $this->devices ??= Device::whereIn('id', array_map('intval', (array) $this->input('device_ids', [])))
            ->orderBy('name')
            ->get();
    }

    /** @return array<string, mixed> just the fields to change */
    public function changes(): array
    {
        $changes = Arr::only((array) $this->validated('changes'), DeviceFieldRules::BULK_FIELDS);

        // Ping-only has no poll credential - drop it, the way the edit form does on the way to none.
        if (($changes['poll_method'] ?? null) === PollMethod::None->value) {
            $changes['credential_id'] = null;
        }

        return $changes;
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return; // field-level problems first; the batch checks assume well-formed input
            }

            $changes = (array) $this->input('changes', []);
            $unknown = array_diff(array_keys($changes), DeviceFieldRules::BULK_FIELDS);
            if ($unknown !== []) {
                $validator->errors()->add('changes', 'These fields can\'t be changed in bulk: '.implode(', ', $unknown).'.');

                return;
            }
            if ($changes === []) {
                $validator->errors()->add('changes', 'Choose at least one thing to change.');

                return;
            }

            $ids = array_map('intval', (array) $this->input('device_ids'));
            $devices = $this->devices();
            if ($devices->count() !== count($ids)) {
                $missing = count($ids) - $devices->count();
                $validator->errors()->add('device_ids', "{$missing} of the selected devices no longer exist. Refresh and try again.");

                return;
            }

            $this->checkCredentials($validator, $changes);
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $problems = [];
            $add = function (Device $d, string $message) use (&$problems): void {
                $problems[$d->id][] = "{$d->name}: {$message}";
            };

            $this->checkPollMethods($devices, $changes, $add);
            $this->checkIpScope($devices, $changes, $add);
            $this->checkParent($devices, $changes, $add);

            $shown = 0;
            foreach ($problems as $id => $messages) {
                foreach ($messages as $m) {
                    if ($shown++ < self::MAX_DEVICE_ERRORS) {
                        $validator->errors()->add("devices.{$id}", $m);
                    }
                }
            }
            if ($shown > self::MAX_DEVICE_ERRORS) {
                $validator->errors()->add('devices', ($shown - self::MAX_DEVICE_ERRORS).' more problems not listed.');
            }
        }];
    }

    /**
     * Each credential has to be the right kind for where it's going. A new SNMP or RouterOS poll
     * method needs its credential chosen in the same change, so no device is left holding a
     * credential of the old type.
     *
     * @param  array<string, mixed>  $changes
     */
    private function checkCredentials(Validator $validator, array $changes): void
    {
        $method = $changes['poll_method'] ?? null;
        if ($method !== null && $method !== PollMethod::None->value && ! array_key_exists('credential_id', $changes)) {
            $validator->errors()->add('changes.credential_id', 'Choose the credential to use with the new poll method.');

            return;
        }

        $types = Credential::whereIn('id', array_filter([
            $changes['credential_id'] ?? null, $changes['ssh_credential_id'] ?? null, $changes['routeros_credential_id'] ?? null,
        ]))->pluck('type', 'id');

        if (($changes['ssh_credential_id'] ?? null) !== null && $types[(int) $changes['ssh_credential_id']] !== 'ssh') {
            $validator->errors()->add('changes.ssh_credential_id', 'The backup credential has to be an SSH credential.');
        }
        if (($changes['routeros_credential_id'] ?? null) !== null && $types[(int) $changes['routeros_credential_id']] !== 'routeros') {
            $validator->errors()->add('changes.routeros_credential_id', 'The OSPF credential has to be a RouterOS credential.');
        }

        $credId = $changes['credential_id'] ?? null;
        if ($credId === null) {
            return;
        }
        $credType = $types[(int) $credId];
        if ($method !== null) {
            if ($credType !== $method) {
                $validator->errors()->add('changes.credential_id', "That credential is {$credType}, which doesn't match the {$method} poll method.");
            }

            return;
        }
        // Credential alone: it has to suit every device's current poll method.
        $methods = $this->devices()->map(fn (Device $d) => $d->poll_method->value)->unique()->values()->all();
        if ($methods !== [$credType]) {
            $others = array_map(fn (string $m) => $m === PollMethod::None->value ? 'ping only' : $m, array_diff($methods, [$credType]));
            $validator->errors()->add('changes.credential_id', "That credential is {$credType}, but some of the selected devices are ".implode(' / ', $others).'. Select devices with the same poll method, or change the poll method too.');
        }
    }

    /**
     * SNMP and RouterOS polling need an address to talk to - the same rule as the single edit.
     *
     * @param  Collection<int, Device>  $devices
     * @param  array<string, mixed>  $changes
     */
    private function checkPollMethods(Collection $devices, array $changes, callable $add): void
    {
        $method = $changes['poll_method'] ?? null;
        if ($method === null || $method === PollMethod::None->value) {
            return;
        }
        foreach ($devices as $d) {
            if ($d->mgmt_ip === null || $d->mgmt_ip === '') {
                $add($d, 'it has no management IP, so it can only be ping-only.');
            }
        }
    }

    /**
     * Moving devices onto an agent (or back to the server) must keep one device per IP in that
     * scope. Unselected devices already there count, and so do two selected devices sharing an
     * IP: they'd all land in the same scope together.
     *
     * @param  Collection<int, Device>  $devices
     * @param  array<string, mixed>  $changes
     */
    private function checkIpScope(Collection $devices, array $changes, callable $add): void
    {
        if (! array_key_exists('agent_id', $changes)) {
            return;
        }
        $agentId = $changes['agent_id'] === null ? null : (int) $changes['agent_id'];
        $withIp = $devices->filter(fn (Device $d) => $d->mgmt_ip !== null && $d->mgmt_ip !== '');
        if ($withIp->isEmpty()) {
            return;
        }

        $others = Device::withoutGlobalScope('visibility')
            ->inPollScope($agentId)
            ->whereIn('mgmt_ip', $withIp->pluck('mgmt_ip')->unique()->all())
            ->whereNotIn('id', $devices->modelKeys())
            ->get(['id', 'name', 'mgmt_ip'])
            ->keyBy('mgmt_ip');
        $where = $agentId === null ? 'on the central server' : 'behind that agent';

        foreach ($withIp->groupBy('mgmt_ip') as $ip => $group) {
            if ($other = $others->get($ip)) {
                foreach ($group as $d) {
                    $add($d, "{$ip} is already used by \"{$other->name}\" {$where}.");
                }
            } elseif ($group->count() > 1) {
                foreach ($group as $d) {
                    $add($d, "{$ip} is shared with another selected device, and both would end up {$where}.");
                }
            }
        }
    }

    /**
     * A shared new parent can't be one of the selected devices or sit below one, or that device
     * would end up under itself.
     *
     * @param  Collection<int, Device>  $devices
     * @param  array<string, mixed>  $changes
     */
    private function checkParent(Collection $devices, array $changes, callable $add): void
    {
        $parentId = $changes['parent_device_id'] ?? null;
        if ($parentId === null) {
            return;
        }
        /** @var array<int, int|null> $parents */
        $parents = Device::query()->pluck('parent_device_id', 'id')->all();
        foreach ($devices as $d) {
            if (NotADeviceDescendant::closesLoop($parents, $d->id, (int) $parentId)) {
                $add($d, (int) $parentId === $d->id
                    ? 'it can\'t be its own parent.'
                    : 'the new parent sits below it, so this would create a loop.');
            }
        }
    }
}
