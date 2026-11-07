<?php

namespace App\Actions\Devices;

use App\Models\Device;
use Illuminate\Support\Facades\DB;

/**
 * Apply one set of changes to many devices, all or nothing. Each device goes through
 * UpdateDevice, so a bulk edit stamps a manual pin exactly as the single edit does. The caller
 * (BulkUpdateDevicesRequest) has already checked every device against the changes.
 */
class BulkUpdateDevices
{
    public function __construct(private readonly UpdateDevice $updateDevice) {}

    /**
     * @param  iterable<Device>  $devices
     * @param  array<string, mixed>  $changes
     * @return int how many devices were updated
     */
    public function __invoke(iterable $devices, array $changes): int
    {
        return DB::transaction(function () use ($devices, $changes): int {
            $n = 0;
            foreach ($devices as $device) {
                ($this->updateDevice)($device, $changes);
                $n++;
            }

            return $n;
        });
    }
}
