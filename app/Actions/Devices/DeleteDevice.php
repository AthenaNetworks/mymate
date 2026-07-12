<?php

namespace App\Actions\Devices;

use App\Models\Device;

class DeleteDevice
{
    public function __invoke(Device $device): void
    {
        $device->delete();
    }
}
