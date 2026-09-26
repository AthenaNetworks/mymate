<?php

namespace App\Services\Polling;

/**
 * The device-page part of a device's live metrics frame (DeviceMetricsUpdated), shared by the
 * central metrics tick and agent ingest. Keys are only there when the poll read them.
 *
 *  - uptime_seconds  as read this tick. The client stamps its own uptime_at when the frame lands,
 *                    so the ticking uptime on screen doesn't depend on the two clocks agreeing.
 *  - cpu_loads       per-processor load, same shape as the device row [{index, load_pct}].
 *  - storage         just `true` when the storage table was read. Storage is a handful of rows
 *                    per device with names and sizes, it moves slowly, and only an open device
 *                    page shows it, so rather than push every device's list to every map viewer
 *                    each tick the page refetches its one small list when it sees this.
 */
final class LiveDeviceFrame
{
    /**
     * @param  array<string, mixed>  $attrs  what RecordDeviceResources::deviceAttributes() put on the row
     * @return array<string, mixed>
     */
    public static function resources(array $attrs, DeviceMetrics $m): array
    {
        $out = [];
        if (isset($attrs['uptime_seconds'])) {
            $out['uptime_seconds'] = (int) $attrs['uptime_seconds'];
        }
        if (! empty($attrs['cpu_loads'])) {
            $out['cpu_loads'] = $attrs['cpu_loads'];
        }
        if ($m->storages !== null) {
            $out['storage'] = true;
        }

        return $out;
    }
}
