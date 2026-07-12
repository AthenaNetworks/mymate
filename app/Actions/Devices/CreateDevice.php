<?php

namespace App\Actions\Devices;

use App\Models\Device;
use App\Models\DeviceMapPosition;
use App\Models\Map;

class CreateDevice
{
    /** @param array<string, mixed> $data */
    public function __invoke(array $data): Device
    {
        $device = Device::create($data);

        // Place every new device on the default map so it never vanishes.
        $map = Map::default();
        if ($map !== null) {
            DeviceMapPosition::firstOrCreate(
                ['device_id' => $device->id, 'map_id' => $map->id],
                ['x' => $device->map_x, 'y' => $device->map_y],
            );
        }

        return $device;
    }
}
