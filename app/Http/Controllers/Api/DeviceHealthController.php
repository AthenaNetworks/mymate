<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceStorage;
use Illuminate\Http\JsonResponse;

/**
 * Current values the device page shows next to its graphs, for the things that don't fit on the
 * device row itself. The history behind them is the `storage` and `cpu` families
 * (App\Actions\History\HistoryFamilies).
 */
class DeviceHealthController extends Controller
{
    /**
     * GET /api/devices/{device}/storage - every storage entry (disks, RAM, swap) as of the last
     * metrics poll. `id` is the storage_id key of the storage history family.
     */
    public function storage(Device $device): JsonResponse
    {
        $rows = $device->storages()->orderByRaw(
            "CASE type WHEN 'ram' THEN 0 WHEN 'virtual_memory' THEN 1 ELSE 2 END"
        )->orderBy('descr')->get()->map(fn (DeviceStorage $s) => [
            'id' => $s->id,
            'key' => $s->storage_key,
            'descr' => $s->descr,
            'type' => $s->type,
            'size_bytes' => $s->size_bytes,
            'used_bytes' => $s->used_bytes,
            'used_pct' => $s->used_pct,
            'updated_at' => $s->updated_at,
        ]);

        return response()->json(['data' => $rows]);
    }

    /**
     * GET /api/devices/{device}/processors - how many processors the last poll saw and the load on
     * each. `index` is the cpu_index key of the cpu history family (hrDeviceIndex over SNMP, the
     * core number over the RouterOS API). cpu_pct is the overall average, same as the device row.
     */
    public function processors(Device $device): JsonResponse
    {
        $loads = array_values(array_filter((array) ($device->cpu_loads ?? []), 'is_array'));

        return response()->json(['data' => [
            'count' => count($loads),
            'cpu_pct' => $device->cpu_pct,
            'processors' => $loads,
            'updated_at' => $device->metrics_at,
        ]]);
    }
}
