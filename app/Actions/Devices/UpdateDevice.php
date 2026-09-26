<?php

namespace App\Actions\Devices;

use App\Models\Device;

class UpdateDevice
{
    /** @param array<string, mixed> $data */
    public function __invoke(Device $device, array $data): Device
    {
        // A coordinate set through the editor is a manual pin - stamp the source so the SNMP
        // auto-derive never overwrites it. Clearing both drops the source too. The edit forms
        // send the coordinates back on every save though, so only a real change counts:
        // renaming an SNMP-placed device mustn't quietly turn its pin manual (GitHub #22).
        if ((array_key_exists('latitude', $data) || array_key_exists('longitude', $data)) && $this->coordsChanged($device, $data)) {
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

    /** @param array<string, mixed> $data */
    private function coordsChanged(Device $device, array $data): bool
    {
        foreach (['latitude', 'longitude'] as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $new = $data[$key];
            $old = $device->{$key};
            if ($new === null || $old === null) {
                if ($new !== $old) {
                    return true;
                }

                continue;
            }
            // The columns are decimal(10,7), anything closer than that is the same point.
            if (abs((float) $new - (float) $old) >= 0.0000001) {
                return true;
            }
        }

        return false;
    }
}
