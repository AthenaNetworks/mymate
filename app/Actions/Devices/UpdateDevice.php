<?php

namespace App\Actions\Devices;

use App\Models\Device;

class UpdateDevice
{
    /** @param array<string, mixed> $data */
    public function __invoke(Device $device, array $data): Device
    {
        $device->update($data);

        return $device;
    }
}
