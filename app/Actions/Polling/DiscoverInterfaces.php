<?php

namespace App\Actions\Polling;

use App\Models\Device;
use App\Models\NetworkInterface;
use App\Services\Polling\ThroughputDriverFactory;
use App\Support\EngineLog;

/**
 * Discover a device's interfaces via its driver and upsert the `interfaces`
 * rows. Idempotent: keyed on (device_id, if_index), so re-running refreshes
 * names/capacity instead of duplicating. Returns the count discovered.
 */
class DiscoverInterfaces
{
    public function __construct(private ThroughputDriverFactory $drivers) {}

    public function __invoke(Device $device): int
    {
        // Surface failures instead of silently leaving 0 interfaces (#9): record the
        // reason on the device so the UI can show "why" + offer a retry.
        try {
            $found = $this->drivers->for($device)->discover($device);
        } catch (\Throwable $e) {
            $message = class_basename($e).': '.$e->getMessage();
            $device->forceFill(['discovery_error' => $message, 'discovered_at' => now()])->save();
            EngineLog::warning('discover: failed', [
                'device_id' => $device->id,
                'device' => $device->name,
                'error' => $message,
            ]);

            return 0;
        }

        foreach ($found as $row) {
            $interface = NetworkInterface::firstOrNew([
                'device_id' => $device->id,
                'if_index' => $row['if_index'],
            ]);

            $interface->name = $row['name'];
            $interface->description = $row['description'] ?? null;

            // Interface speed is read-only from SNMP: always write the
            // discovered capacity. Bandwidth overrides live on the link, not here.
            $interface->speed_mbps = $row['speed_mbps'];

            $interface->save();
        }

        $count = count($found);
        $device->forceFill([
            // An empty result is still a problem worth surfacing (wrong community / no access).
            'discovery_error' => $count === 0
                ? 'No interfaces returned - check the SNMP community / API access and that the device is reachable.'
                : null,
            'discovered_at' => now(),
        ])->save();

        EngineLog::debug('discover: complete', [
            'device_id' => $device->id,
            'device' => $device->name,
            'interfaces' => $count,
        ]);

        return $count;
    }
}
