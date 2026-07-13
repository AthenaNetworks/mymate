<?php

namespace App\Support;

use App\Models\Device;

/**
 * Read-only helpers over the device parent/child tree (`devices.parent_device_id`).
 * Used by ordered bulk upgrades to sequence work downstream-first.
 */
class DeviceHierarchy
{
    /**
     * Order the given device ids **downstream-first**: a device always comes before
     * its parent, so the furthest-downstream gear is upgraded first and you walk up
     * the tree - never cutting the path to devices still pending.
     *
     * Ordering key = depth (hops to root via `parent_device_id`), deepest first;
     * ties break by id for a stable order. Cycle-guarded (a malformed self-FK loop
     * can't hang it).
     *
     * @param  list<int>  $deviceIds
     * @return list<int>
     */
    public function orderDownstreamFirst(array $deviceIds): array
    {
        $depths = $this->depths();
        $depth = fn (int $id): int => $depths[$id] ?? 0;

        $ids = array_values(array_unique(array_map('intval', $deviceIds)));
        usort($ids, fn (int $a, int $b): int => $depth($b) <=> $depth($a) ?: $a <=> $b);

        return $ids;
    }

    /**
     * Hops-to-root depth for every device (via `parent_device_id`). A leaf/CPE has the
     * highest depth, the core the lowest - the "distance" a rolling upgrade orders by.
     * Cycle-guarded.
     *
     * @return array<int, int> id => depth
     */
    public function depths(): array
    {
        /** @var array<int, int|null> $parents id => parent_device_id */
        $parents = Device::query()->pluck('parent_device_id', 'id')->all();

        $cache = [];
        foreach (array_keys($parents) as $id) {
            $id = (int) $id;
            $d = 0;
            $seen = [];
            $cur = $id;
            while (! empty($parents[$cur])) {
                if (isset($seen[$cur])) {
                    break; // cycle - stop counting
                }
                $seen[$cur] = true;
                $cur = (int) $parents[$cur];
                $d++;
            }
            $cache[$id] = $d;
        }

        return $cache;
    }
}
