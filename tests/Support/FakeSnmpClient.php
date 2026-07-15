<?php

namespace Tests\Support;

use App\Services\Snmp\SnmpClient;
use App\Services\Snmp\SnmpClientException;
use App\Services\Snmp\SnmpCredential;

/**
 * In-memory SnmpClient for tests - no hardware.
 *
 * - `$walks[$oid]` scripts a table walk -> [ifIndex => value].
 * - `$getsByCommunity[$community]` scripts a scalar GET -> [oid => value] (used by
 *   discovery to identify a host by sysName). An unscripted community returns []
 *   by default, or throws (wrong community / timeout) when `$throwOnUnknownGet`.
 */
class FakeSnmpClient implements SnmpClient
{
    /** @var array<string, array<int|string, string>> */
    public array $walks = [];

    /** @var array<string, array<string, string>> community => [oid => value] */
    public array $getsByCommunity = [];

    /** When true, a GET with an unscripted community throws (simulates no response). */
    public bool $throwOnUnknownGet = false;

    /** When true, a table walk throws (simulates an SNMP timeout / filtered port). */
    public bool $throwOnWalk = false;

    public function get(string $host, SnmpCredential $cred, array $oids): array
    {
        // Discovery scripts responses by the identity that answered - the community for v1/v2c,
        // the USM user for v3.
        $key = $cred->version === '3' ? $cred->secName : $cred->community;
        if (array_key_exists($key, $this->getsByCommunity)) {
            return $this->getsByCommunity[$key];
        }

        if ($this->throwOnUnknownGet) {
            throw new SnmpClientException("SNMP get failed for {$host}: no response");
        }

        return [];
    }

    public function walk(string $host, SnmpCredential $cred, string $baseOid): array
    {
        if ($this->throwOnWalk) {
            throw new SnmpClientException("SNMP walk failed for {$host}: no response");
        }

        return $this->walks[$baseOid] ?? [];
    }
}
