<?php

namespace App\Services\Snmp;

/**
 * Thin abstraction over an SNMP transport so callers depend on this interface,
 * not a concrete extension/package. Keeps the driver fakeable in tests (no
 * hardware) and lets us swap php-snmp for e.g. freedsx/snmp later.
 *
 * v1/v2c (community) and v3 (USM auth/priv) - the SnmpCredential value object carries whichever
 * applies. Uses 64-bit ifHC counters + GETBULK. Implementations MUST use a short timeout and
 * fail fast - a filtered/black-holing host must never wedge a worker.
 */
interface SnmpClient
{
    /**
     * GET one or more scalar OIDs.
     *
     * @param  list<string>  $oids
     * @return array<string, string> oid => value (SNMP_VALUE_PLAIN, no type prefix)
     *
     * @throws SnmpClientException on timeout / transport error.
     */
    public function get(string $host, SnmpCredential $cred, array $oids): array;

    /**
     * Walk a table column (GETBULK). Keys are the OID **suffix** relative to the
     * walked column - for the if(X)Table that suffix is the `ifIndex`.
     *
     * @return array<string, string> indexSuffix => value
     *
     * @throws SnmpClientException on timeout / transport error.
     */
    public function walk(string $host, SnmpCredential $cred, string $baseOid): array;
}
