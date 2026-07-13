<?php

namespace App\Actions\Devices;

use App\Enums\DeviceStatus;
use App\Enums\PollMethod;
use App\Models\Device;
use App\Models\Link;
use App\Support\DeviceHierarchy;
use Illuminate\Support\Collection;

/**
 * Plan a bulk upgrade before running it: order the devices downstream-first (furthest
 * from the core first) and decide which to upgrade vs skip - and why. Read-only; used by
 * the dry-run endpoint, the rolling-upgrades page (which also shows the topology context)
 * and by RunBulkUpgrade to mark skips up front.
 *
 * Skip reasons: not a RouterOS device - device is down - parent is down (path
 * unreachable) - already up to date (latest is not newer than installed).
 *
 * When $preserveOrder is true the given order is kept as-is (the operator re-ordered the
 * list by hand); otherwise it's sorted furthest-downstream-first.
 */
class UpgradePreflight
{
    public function __construct(private DeviceHierarchy $hierarchy) {}

    /**
     * @param  list<int>  $deviceIds
     * @return array{order: list<int>, upgrade: list<int>, plan: list<array{device_id:int, name:string, action:string, reason:?string, status:string, depth:int, os_version:?string, latest_version:?string, parent_name:?string, neighbours:list<string>}>}
     */
    public function __invoke(array $deviceIds, bool $preserveOrder = false): array
    {
        /** @var Collection<int, Device> $byId */
        $byId = Device::all()->keyBy('id');

        $selected = array_values(array_filter(
            array_unique(array_map('intval', $deviceIds)),
            fn (int $id): bool => $byId->has($id),
        ));

        $order = $preserveOrder ? $selected : $this->hierarchy->orderDownstreamFirst($selected);
        $depths = $this->hierarchy->depths();
        $neighbours = $this->neighbours($byId);

        $plan = [];
        $upgrade = [];
        foreach ($order as $id) {
            /** @var Device $device */
            $device = $byId->get($id);
            $reason = $this->skipReason($device, $byId);
            $parent = $device->parent_device_id !== null ? $byId->get($device->parent_device_id) : null;
            $plan[] = [
                'device_id' => $id,
                'name' => $device->name,
                'action' => $reason === null ? 'upgrade' : 'skip',
                'reason' => $reason,
                'status' => $device->status->value,
                'depth' => $depths[$id] ?? 0,
                'os_version' => $device->os_version,
                'latest_version' => $device->latest_version,
                'parent_name' => $parent?->name,
                'neighbours' => array_values($neighbours[$id] ?? []),
            ];
            if ($reason === null) {
                $upgrade[] = $id;
            }
        }

        return ['order' => $order, 'upgrade' => $upgrade, 'plan' => $plan];
    }

    /**
     * device_id => list of linked peer device names, so the UI can show what connects to
     * what and the operator can eyeball which end is furthest out.
     *
     * @param  Collection<int, Device>  $byId
     * @return array<int, list<string>>
     */
    private function neighbours(Collection $byId): array
    {
        $out = [];
        foreach (Link::query()->select('a_device_id', 'b_device_id')->get() as $link) {
            $a = (int) $link->a_device_id;
            $b = (int) $link->b_device_id;
            if (($nameB = $byId->get($b)?->name) !== null) {
                $out[$a][$nameB] = $nameB;
            }
            if (($nameA = $byId->get($a)?->name) !== null) {
                $out[$b][$nameA] = $nameA;
            }
        }

        return array_map('array_values', $out);
    }

    /** @param  Collection<int, Device>  $byId */
    private function skipReason(Device $device, Collection $byId): ?string
    {
        if ($device->poll_method !== PollMethod::RouterOs) {
            return 'not a RouterOS device';
        }
        if ($device->status === DeviceStatus::Down) {
            return 'device is down';
        }
        $parent = $device->parent_device_id !== null ? $byId->get($device->parent_device_id) : null;
        if ($parent !== null && $parent->status === DeviceStatus::Down) {
            return "parent {$parent->name} is down";
        }
        if ($device->latest_version !== null && ! UpgradeDevice::isNewer($device->latest_version, $device->os_version)) {
            return 'already up to date';
        }

        return null;
    }
}
