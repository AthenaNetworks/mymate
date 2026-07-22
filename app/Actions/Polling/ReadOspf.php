<?php

namespace App\Actions\Polling;

use App\Models\Credential;
use App\Services\RouterOs\RouterOsClient;
use App\Services\RouterOs\RouterOsTarget;

/**
 * Read a MikroTik's OSPF state over the RouterOS API (GitHub #11) - the full-neighbour count
 * and the per-interface cost (metric) - since RouterOS exposes none of this over SNMP. One
 * connection serves both. Best-effort: any failure yields nulls / an empty cost map.
 */
class ReadOspf
{
    public function __construct(private RouterOsClient $client) {}

    /**
     * @return array{neighbors: ?int, costs: array<string, int>}
     */
    public function __invoke(string $host, Credential $cred): array
    {
        $none = ['neighbors' => null, 'costs' => []];
        if ($cred->type !== 'routeros' || ! $cred->username) {
            return $none;
        }

        $port = $cred->api_port ?: 8728;
        try {
            $conn = $this->client->open(new RouterOsTarget(
                host: $host,
                port: $port,
                username: (string) $cred->username,
                password: (string) $cred->password,
                timeout: max(1, (int) config('mymate.routeros.timeout', 3)),
                ssl: $port === 8729,
            ));
            try {
                $neighbors = self::countFull($conn->query('/routing/ospf/neighbor/print'));
                $costs = self::costsByInterface($conn->query('/routing/ospf/interface/print'));
            } finally {
                $conn->close();
            }
        } catch (\Throwable) {
            return $none;
        }

        return ['neighbors' => $neighbors, 'costs' => $costs];
    }

    /**
     * Count neighbours in the "Full" state (a fully-formed adjacency). RouterOS 6 and 7 both
     * label it "Full".
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public static function countFull(array $rows): int
    {
        $full = 0;
        foreach ($rows as $row) {
            if (str_contains(strtolower((string) ($row['state'] ?? '')), 'full')) {
                $full++;
            }
        }

        return $full;
    }

    /**
     * Map each OSPF interface name to its cost (outbound metric). An interface can appear more
     * than once (multiple areas/instances); the last value wins - they're normally identical.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    public static function costsByInterface(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $name = (string) ($row['interface'] ?? '');
            if ($name !== '' && isset($row['cost']) && is_numeric($row['cost'])) {
                $out[$name] = (int) $row['cost'];
            }
        }

        return $out;
    }
}
