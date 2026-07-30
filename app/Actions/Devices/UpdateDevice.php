<?php

namespace App\Actions\Devices;

use App\Models\Device;

class UpdateDevice
{
    /** @param array<string, mixed> $data */
    public function __invoke(Device $device, array $data): Device
    {
        // A coordinate set through the editor is a manual pin - stamp the source so the SNMP
        // auto-derive never overwrites it. Clearing both drops the source too.
        if (array_key_exists('latitude', $data) || array_key_exists('longitude', $data)) {
            $data['geo_source'] = ($data['latitude'] ?? null) !== null && ($data['longitude'] ?? null) !== null ? 'manual' : null;
        }

        // Assigning (or clearing) a site through the editor is a manual decision - stamp the
        // source so an import or nearest-site pass never overrides the operator.
        if (array_key_exists('site_id', $data)) {
            $data['site_source'] = $data['site_id'] !== null ? 'manual' : null;
        }

        $device->update($data);

        return $device;
    }
}
