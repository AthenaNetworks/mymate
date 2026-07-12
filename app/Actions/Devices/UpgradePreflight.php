<?php

namespace App\Actions\Devices;

use App\Enums\DeviceStatus;
use App\Enums\PollMethod;
use App\Models\Device;
use App\Support\DeviceHierarchy;
use Illuminate\Support\Collection;

/**
 * Plan a bulk upgrade before running it: order the devices
 * downstream-first and decide which to upgrade vs skip - and why. Read-only; used
 * by the dry-run endpoint and by RunBulkUpgrade to mark skips up front.
 *
 * Skip reasons: not a RouterOS device - device is down - parent is down (path
 * unreachable) - already up to date (latest is not newer than installed).
 */
class UpgradePreflight
{
    public function __construct(private DeviceHierarchy $hierarchy) {}

    /**
     * @param  list<int>  $deviceIds
     * @return array{order: list<int>, upgrade: list<int>, plan: list<array{device_id:int, name:string, action:string, reason:?string}>}
     */
    public function __invoke(array $deviceIds): array
    {
        /** @var Collection<int, Device> $byId */
        $byId = Device::all()->keyBy('id');

        $selected = array_values(array_filter(
            array_unique(array_map('intval', $deviceIds)),
            fn (int $id): bool => $byId->has($id),
        ));

        $order = $this->hierarchy->orderDownstreamFirst($selected);

        $plan = [];
        $upgrade = [];
        foreach ($order as $id) {
            /** @var Device $device */
            $device = $byId->get($id);
            $reason = $this->skipReason($device, $byId);
            $plan[] = [
                'device_id' => $id,
                'name' => $device->name,
                'action' => $reason === null ? 'upgrade' : 'skip',
                'reason' => $reason,
            ];
            if ($reason === null) {
                $upgrade[] = $id;
            }
        }

        return ['order' => $order, 'upgrade' => $upgrade, 'plan' => $plan];
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
