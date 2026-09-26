<?php

namespace App\Services\Polling;

use App\Actions\Devices\CaptureDeviceFacts;
use App\Actions\Devices\UpgradeDevice;
use App\Models\Device;
use App\Services\RouterOs\RouterOsClient;
use App\Services\RouterOs\RouterOsConnection;
use App\Services\RouterOs\RouterOsTarget;

/**
 * CPU / memory / temperature + wireless RF over the RouterOS binary API (MikroTik). Reads
 * `/system/resource` (cpu-load + free/total memory, and for the device page the uptime and the
 * memory / disk sizes as storage entries), best-effort `/system/resource/cpu` (per-core load),
 * best-effort `/system/health`
 * (board/CPU temperature - shape differs across RouterOS 6 and 7, both handled), and the
 * wireless registration table (signal / SNR / CCQ / client count) when the board has radios -
 * legacy wireless, wifiwave2, the 7.13+ wifi menu or a CAPsMAN controller, see RouterOsWireless.
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

            // Keep the running version fresh: if this poll finds a different RouterOS version
            // (upgraded/downgraded, here or out-of-band) update our record right away rather
            // than waiting for the slow discovery/facts cadence.
            $version = UpgradeDevice::normalizeVersion((string) ($res['version'] ?? ''));
            if ($version !== null && $version !== $device->os_version) {
                $device->forceFill(['os_version' => $version])->save();
            }

            $cpu = isset($res['cpu-load']) && is_numeric($res['cpu-load']) ? (float) $res['cpu-load'] : null;

            $mem = null;
            $total = (float) ($res['total-memory'] ?? 0);
            $free = (float) ($res['free-memory'] ?? 0);
            if ($total > 0) {
                $mem = (($total - $free) / $total) * 100;
            }

            $wl = RouterOsWireless::read($conn, $device);
            $uptime = trim((string) ($res['uptime'] ?? ''));

            return new DeviceMetrics(
                cpuPct: DeviceMetrics::clampPct($cpu),
                memUsedPct: DeviceMetrics::clampPct($mem),
                tempC: $this->temperature($conn),
                signalDbm: $wl['signal'],
                snrDb: $wl['snr'],
                ccqPct: DeviceMetrics::clampPct($wl['ccq']),
                wirelessClients: $wl['clients'],
                uptimeSeconds: $uptime !== '' ? CaptureDeviceFacts::parseRouterOsUptime($uptime) : null,
                cpuLoads: $this->cpuLoads($conn),
                storages: self::storagesFromResource($res),
                // OSPF (neighbours + interface costs) is read separately in PollDeviceMetrics via
                // ReadOspf, so it works the same for a routeros-polled device and an snmp-polled
                // one with a routeros credential attached.
            );
        } finally {
            $conn->close();
        }
    }

    /**
     * Load per core from `/system/resource/cpu/print` (one row per core: cpu=cpu0, load=12).
     * Indexed by row order, which is the core number. Best-effort like health, an older
     * RouterOS without the menu just gives no per-core graph.
     *
     * @return array<int, float>|null
     */
    private function cpuLoads(RouterOsConnection $conn): ?array
    {
        try {
            $rows = $conn->query('/system/resource/cpu/print');
        } catch (\Throwable) {
            return null;
        }

        $out = [];
        foreach (array_values($rows) as $i => $row) {
            $index = preg_match('/(\d+)$/', (string) ($row['cpu'] ?? ''), $m) === 1 ? (int) $m[1] : $i;
            if (isset($row['load']) && is_numeric($row['load'])) {
                $out[$index] = max(0.0, min(100.0, (float) $row['load']));
            }
        }
        ksort($out);

        return $out === [] ? null : $out;
    }

    /**
     * Storage from the `/system/resource/print` row we already have: main memory and the system
     * disk (flash on most boards). Keyed by fixed names since the API has no hrStorageIndex.
     *
     * @param  array<string, string>  $res
     * @return list<StorageReading>|null
     */
    public static function storagesFromResource(array $res): ?array
    {
        $out = [];
        $entries = [
            'memory' => ['main memory', 'ram', 'total-memory', 'free-memory'],
            'system-disk' => ['system disk', 'flash', 'total-hdd-space', 'free-hdd-space'],
        ];
        foreach ($entries as $key => [$descr, $type, $totalField, $freeField]) {
            $total = isset($res[$totalField]) && is_numeric($res[$totalField]) ? (int) $res[$totalField] : 0;
            if ($total <= 0) {
                continue;
            }
            $free = isset($res[$freeField]) && is_numeric($res[$freeField]) ? (int) $res[$freeField] : null;
            $out[] = new StorageReading($key, $descr, $type, $total, $free === null ? null : max(0, $total - $free));
        }

        return $res === [] ? null : $out;
    }

    /** Best-effort - /system/health is unavailable on some boards; never let it fail the read. */
    private function temperature(RouterOsConnection $conn): ?float
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
