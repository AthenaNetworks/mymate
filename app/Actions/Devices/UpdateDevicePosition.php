<?php

namespace App\Actions\Devices;

use App\Models\Device;

class UpdateDevicePosition
{
    public function __invoke(Device $device, float $x, float $y): Device
    {
        $device->update(['map_x' => $x, 'map_y' => $y]);

        return $device;
    }
}
