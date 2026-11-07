<?php

namespace App\Rules;

use App\Models\Device;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects a `parent_device_id` that is the device itself, or any device already sitting
 * *below* it in the hierarchy - either would close a loop (A under B under A).
 *
 * Every reader of the parent chain is cycle-guarded (DeviceHierarchy::depths,
 * EvaluateAlerts' ancestor walk, DeviceGeo::resolve), so a loop doesn't hang anything -
 * it just quietly makes the hierarchy wrong: a suppressed alert that should have fired,
 * an upgrade ordered against its own dependencies, coordinates that never resolve to a
 * pin. Cheap to refuse at the door, expensive to notice later - and re-parenting is a
 * two-click gesture on the map now (GitHub #45), not a rare trip to the Devices page.
 *
 * Only applies when editing an existing device; a device being created has no descendants
 * yet, so `exists:devices,id` is the whole guard there.
 */
class NotADeviceDescendant implements ValidationRule
{
    public function __construct(private readonly ?int $deviceId) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->deviceId === null || $value === null || $value === '') {
            return; // creating, or clearing the parent - nothing to loop
        }

        /** @var array<int, int|null> $parents id => parent_device_id, for the whole fleet */
        $parents = Device::query()->pluck('parent_device_id', 'id')->all();

        if (self::closesLoop($parents, $this->deviceId, (int) $value)) {
            $fail($this->reason((int) $value));
        }
    }

    /**
     * Would hanging $deviceId under $parentId close a loop? Walks up from the *proposed* parent;
     * reaching the device means the parent is the device itself or one of its own descendants.
     * Cycle-guarded so a loop already in the data (an import, say) can't spin here. Takes the
     * parent map so a bulk edit can load it once for every device it checks.
     *
     * @param  array<int, int|null>  $parents  id => parent_device_id
     */
    public static function closesLoop(array $parents, int $deviceId, int $parentId): bool
    {
        $seen = [];
        $cursor = $parentId;
        while (! isset($seen[$cursor])) {
            if ($cursor === $deviceId) {
                return true;
            }
            $seen[$cursor] = true;
            $next = $parents[$cursor] ?? null;
            if ($next === null) {
                return false; // reached a root without meeting the device - no loop
            }
            $cursor = (int) $next;
        }

        return false;
    }

    /** Name the conflict, so the operator knows which device is in the way. */
    private function reason(int $parentId): string
    {
        if ($parentId === $this->deviceId) {
            return 'A device cannot be its own parent.';
        }

        $name = Device::whereKey($parentId)->value('name') ?? 'That device';

        return "{$name} already sits below this device, so making it the parent would create a loop.";
    }
}
