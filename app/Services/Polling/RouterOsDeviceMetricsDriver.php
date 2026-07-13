<?php

namespace App\Services\Polling;

use App\Models\Device;
use App\Services\RouterOs\RouterOsClient;
use App\Services\RouterOs\RouterOsTarget;

/**
 * CPU / memory / temperature over the RouterOS binary API (MikroTik). Reads
 * `/system/resource` (cpu-load + free/total memory) and best-effort `/system/health`
 * (board/CPU temperature - shape differs across RouterOS 6 and 7, both handled).
 */
class RouterOsDeviceMetricsDriver implements DeviceMetricsDriver
{
    public function __construct(private RouterOsClient $client) {}

    public function sample(Device $device): DeviceMetrics
    {
        $conn = $this->client->open(RouterOsTarget::fromDevice($device));

        try {
            // The API needs the /print action - a bare "/system/resource" traps with
            // "no such command" and the whole read silently comes back null.
            $res = $conn->query('/system/resource/print')[0] ?? [];

            $cpu = isset($res['cpu-load']) && is_numeric($res['cpu-load']) ? (float) $res['cpu-load'] : null;

            $mem = null;
            $total = (float) ($res['total-memory'] ?? 0);
            $free = (float) ($res['free-memory'] ?? 0);
            if ($total > 0) {
                $mem = (($total - $free) / $total) * 100;
            }

            return new DeviceMetrics(
                cpuPct: DeviceMetrics::clampPct($cpu),
                memUsedPct: DeviceMetrics::clampPct($mem),
                tempC: $this->temperature($conn),
            );
        } finally {
            $conn->close();
        }
    }

    /** Best-effort - /system/health is unavailable on some boards; never let it fail the read. */
    private function temperature(\App\Services\RouterOs\RouterOsConnection $conn): ?float
    {
        try {
            $rows = $conn->query('/system/health/print');
        } catch (\Throwable) {
            return null;
        }

        $temps = [];
        foreach ($rows as $row) {
            // RouterOS 6: a single row with a `temperature` (and maybe `cpu-temperature`) key.
            foreach (['cpu-temperature', 'temperature', 'board-temperature'] as $key) {
                if (isset($row[$key]) && is_numeric($row[$key])) {
                    $temps[] = (float) $row[$key];
                }
            }
            // RouterOS 7: one row per sensor, {name: "...temperature", value: "42"}.
            $name = strtolower((string) ($row['name'] ?? ''));
            if (str_contains($name, 'temperature') && isset($row['value']) && is_numeric($row['value'])) {
                $temps[] = (float) $row['value'];
            }
        }

        $temps = array_filter($temps, static fn (float $v): bool => $v > 0);

        return $temps === [] ? null : max($temps);
    }
}
