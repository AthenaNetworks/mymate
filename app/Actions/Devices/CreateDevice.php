<?php

namespace App\Actions\Devices;

use App\Models\Device;
use App\Models\DeviceMapPosition;
use App\Models\Map;

class CreateDevice
{
    /**
     * @param  array<string, mixed>  $data  device attributes, plus an optional `place_on_map`
     *                                      flag (default true) that is consumed here, not stored
     */
    public function __invoke(array $data): Device
    {
        // Place every new device on the default map so it never vanishes - unless the caller
        // opted out (e.g. a client device the operator wants monitored but off every map; the
        // map toolbar also opts out and places on the *active* map itself).
        $placeOnMap = (bool) ($data['place_on_map'] ?? true);
        unset($data['place_on_map']);

        $device = Device::create($data);

        $map = $placeOnMap ? Map::default() : null;
        if ($map !== null) {
            DeviceMapPosition::firstOrCreate(
                ['device_id' => $device->id, 'map_id' => $map->id],
                ['x' => $device->map_x, 'y' => $device->map_y],
            );
        }

        return $device;
    }
}
