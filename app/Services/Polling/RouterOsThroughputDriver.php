<?php

namespace App\Services\Polling;

use App\Models\Device;
use App\Services\RouterOs\RouterOsClient;
use App\Services\RouterOs\RouterOsClientException;
use App\Services\RouterOs\RouterOsConnection;
use App\Services\RouterOs\RouterOsTarget;

/**
 * Throughput via the RouterOS binary API (no SNMP needed).
 *
 * discover(): `/interface/print` (name + a stable index from `.id`) + capacity
 *             from `/interface/ethernet print` where available.
 * sample():   `/interface/monitor-traffic once` -> rx/tx **bits per second directly**
 *             (InterfaceSample::rates) - no counter state, no delta, no reset guard.
 *
 * A filtered/black-holing API port fails fast via RouterOsClientException (the
 * orchestrator isolates it per device).
 */
class RouterOsThroughputDriver implements ThroughputDriver
{
    public function __construct(private RouterOsClient $client) {}

    public function discover(Device $device): array
    {
        $conn = $this->client->open($this->target($device));

        try {
            $speeds = $this->ethernetSpeeds($conn);

            $interfaces = [];
            foreach ($conn->query('/interface/print') as $row) {
                $ifIndex = $this->ifIndex($row);
                if ($ifIndex === null) {
                    continue;
                }
                $name = ($row['name'] ?? '') !== '' ? $row['name'] : "if{$ifIndex}";
                $comment = trim((string) ($row['comment'] ?? ''));

                $interfaces[] = [
                    'if_index' => $ifIndex,
                    'name' => $name,
                    'description' => $comment !== '' ? $comment : null,
                    'speed_mbps' => $speeds[$name] ?? null,
                ];
            }

            return $interfaces;
        } finally {
            $conn->close();
        }
    }

    public function sample(Device $device): array
    {
        $conn = $this->client->open($this->target($device));

        try {
            // name -> ifIndex map (fresh, so monitor-traffic targets current names), plus each
            // port's oper status from `running` (false = link down / admin-disabled). SNMP carries
            // ifOperStatus for free; the API doesn't, so read it here or the per-interface down
            // alert (GitHub #22) never sees a RouterOS-polled port go down.
            $ifIndexByName = [];
            $operByName = [];
            foreach ($conn->query('/interface/print') as $row) {
                $ifIndex = $this->ifIndex($row);
                if ($ifIndex !== null && ($row['name'] ?? '') !== '') {
                    $ifIndexByName[$row['name']] = $ifIndex;
                    $operByName[$row['name']] = $this->boolFlag($row['running'] ?? null);
                }
            }
            if ($ifIndexByName === []) {
                return [];
            }

            $replies = $conn->query('/interface/monitor-traffic', [
                'interface' => implode(',', array_keys($ifIndexByName)),
                'once' => '',
            ]);

            $samples = [];
            foreach ($replies as $reply) {
                $name = $reply['name'] ?? null;
                if ($name === null || ! isset($ifIndexByName[$name])) {
                    continue;
                }

                $samples[$ifIndexByName[$name]] = InterfaceSample::rates(
                    (float) ($reply['rx-bits-per-second'] ?? 0),
                    (float) ($reply['tx-bits-per-second'] ?? 0),
                    $operByName[$name] ?? null,
                );
            }

            // monitor-traffic can skip a down port, so it would never land in $samples and its
            // oper_status would go stale. Record the down ones explicitly (zero throughput) so
            // they're seen as down.
            foreach ($ifIndexByName as $name => $ifIndex) {
                if (! isset($samples[$ifIndex]) && ($operByName[$name] ?? null) === false) {
                    $samples[$ifIndex] = InterfaceSample::rates(0.0, 0.0, false);
                }
            }

            return $samples;
        } finally {
            $conn->close();
        }
    }

    /** Parse a RouterOS API boolean field ("true"/"false", older "yes"/"no") to a tri-state. */
    private function boolFlag(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    /**
     * Resolve connection details from the device's RouterOS credential.
     */
    private function target(Device $device): RouterOsTarget
    {
        return RouterOsTarget::fromDevice($device);
    }

    /**
     * name => capacity Mbps. The negotiated link rate isn't in `/interface/ethernet
     * print` - it lives in `/interface/ethernet monitor once` (`rate`, e.g. "1Gbps";
     * a down/no-link port has none -> left null -> user-overridable).
     */
    private function ethernetSpeeds(RouterOsConnection $conn): array
    {
        $names = array_values(array_filter(array_map(
            static fn (array $row): string => (string) ($row['name'] ?? ''),
            $conn->query('/interface/ethernet/print'),
        )));
        if ($names === []) {
            return [];
        }

        $speeds = [];
        foreach ($conn->query('/interface/ethernet/monitor', ['numbers' => implode(',', $names), 'once' => '']) as $row) {
            $name = $row['name'] ?? null;
            $mbps = self::parseSpeedMbps($row['rate'] ?? '');
            if ($name !== null && $mbps !== null) {
                $speeds[$name] = $mbps;
            }
        }

        return $speeds;
    }

    /** Stable per-device integer index from a RouterOS `.id` like "*A" (hex). */
    private function ifIndex(array $row): ?int
    {
        $id = ltrim($row['.id'] ?? '', '*');

        return $id === '' ? null : (int) hexdec($id);
    }

    /** "1Gbps" -> 1000, "100Mbps" -> 100, "10Gbps" -> 10000; null if unparseable. */
    public static function parseSpeedMbps(string $rate): ?int
    {
        if (preg_match('/^([\d.]+)\s*([GMK]?)bps$/i', trim($rate), $m) !== 1) {
            return null;
        }

        $value = (float) $m[1];
        $mbps = match (strtoupper($m[2])) {
            'G' => $value * 1000,
            'K' => $value / 1000,
            default => $value, // M or bare
        };

        return (int) round($mbps);
    }
}
