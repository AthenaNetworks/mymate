<?php

namespace App\Actions\Polling;

use App\Models\Credential;
use App\Services\RouterOs\RouterOsClient;
use App\Services\RouterOs\RouterOsTarget;

/**
 * Read a MikroTik's OSPF full-neighbour count over the RouterOS API using a given credential
 * (GitHub #11) - so an SNMP-polled router (whose SNMP can't expose OSPF at all) can still report
 * it via a separate RouterOS-API credential. Best-effort: any failure returns null.
 */
class ReadOspfNeighbors
{
    public function __construct(private RouterOsClient $client) {}

    public function __invoke(string $host, Credential $cred): ?int
    {
        if ($cred->type !== 'routeros' || ! $cred->username) {
            return null;
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
                $rows = $conn->query('/routing/ospf/neighbor/print');
            } finally {
                $conn->close();
            }
        } catch (\Throwable) {
            return null;
        }

        return self::countFull($rows);
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
}
