<?php

namespace App\Services\Polling;

use App\Models\Device;
use App\Services\RouterOs\RouterOsClient;
use App\Services\RouterOs\RouterOsTarget;

/**
 * CPU / memory / temperature + wireless RF over the RouterOS binary API (MikroTik). Reads
 * `/system/resource` (cpu-load + free/total memory), best-effort `/system/health`
 * (board/CPU temperature - shape differs across RouterOS 6 and 7, both handled), and the
 * wireless registration table (signal / SNR / CCQ / client count) when the board has radios.
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
            $version = \App\Actions\Devices\UpgradeDevice::normalizeVersion((string) ($res['version'] ?? ''));
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

            $wl = $this->wireless($conn);

            return new DeviceMetrics(
                cpuPct: DeviceMetrics::clampPct($cpu),
                memUsedPct: DeviceMetrics::clampPct($mem),
                tempC: $this->temperature($conn),
                signalDbm: $wl['signal'],
                snrDb: $wl['snr'],
                ccqPct: DeviceMetrics::clampPct($wl['ccq']),
                wirelessClients: $wl['clients'],
                ospfNeighbors: $this->ospfFullNeighbors($conn),
            );
        } finally {
            $conn->close();
        }
    }

    /**
     * Count OSPF neighbours in the "Full" state (a fully-formed adjacency). The standard
     * OSPF-MIB isn't exposed over SNMP on RouterOS, so this is the only way to read it.
     * Best-effort - a router without OSPF (or on a build where the path differs) just returns
     * null, never an error. RouterOS 6 and 7 both label a full adjacency "Full".
     */
    private function ospfFullNeighbors(\App\Services\RouterOs\RouterOsConnection $conn): ?int
    {
        try {
            $rows = $conn->query('/routing/ospf/neighbor/print');
        } catch (\Throwable) {
            return null; // OSPF package not present / command unavailable
        }

        return \App\Actions\Polling\ReadOspfNeighbors::countFull($rows);
    }

    /**
     * Wireless RF from the registration table: one row per associated station (an AP sees
     * its clients; a CPE in station mode sees the one AP). We report the client count and the
     * average signal / SNR / CCQ across the rows. Best-effort - a board with no wireless (or
     * running wifiwave2/CAPsMAN, a different path) just leaves these null.
     *
     * @return array{signal:?float, snr:?float, ccq:?float, clients:?int}
     */
    private function wireless(\App\Services\RouterOs\RouterOsConnection $conn): array
    {
        try {
            $rows = $conn->query('/interface/wireless/registration-table/print');
        } catch (\Throwable) {
            return ['signal' => null, 'snr' => null, 'ccq' => null, 'clients' => null];
        }

        if ($rows === []) {
            return ['signal' => null, 'snr' => null, 'ccq' => null, 'clients' => null];
        }

        $signals = $snrs = $ccqs = [];
        foreach ($rows as $row) {
            // signal-strength is like "-65dBm@6Mbps" or "-65"; pull the leading number.
            $s = self::firstNumber($row['signal-strength'] ?? null);
            if ($s !== null) {
                $signals[] = $s;
            }
            $n = self::firstNumber($row['signal-to-noise'] ?? null);
            if ($n !== null) {
                $snrs[] = $n;
            }
            $c = self::firstNumber($row['tx-ccq'] ?? null);
            if ($c !== null) {
                $ccqs[] = $c;
            }
        }

        $avg = static fn (array $v): ?float => $v === [] ? null : round(array_sum($v) / count($v), 1);

        return [
            'signal' => $avg($signals),
            'snr' => $avg($snrs),
            'ccq' => $avg($ccqs),
            'clients' => count($rows),
        ];
    }

    /** First signed/decimal number in a value (e.g. "-65dBm@6Mbps" -> -65.0), or null. */
    private static function firstNumber(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        return preg_match('/-?\d+(\.\d+)?/', (string) $value, $m) === 1 ? (float) $m[0] : null;
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
