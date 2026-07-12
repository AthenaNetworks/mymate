<?php

namespace App\Services\Snmp;

use SNMP;
use Throwable;

/**
 * SnmpClient backed by PHP's native `ext-snmp` (the OO `SNMP` class), SNMP v2c.
 *
 * - OID_OUTPUT_NUMERIC + suffix-as-keys means walk() returns [ifIndex => value].
 * - Short timeout (microseconds) + retries so a filtered port fails fast.
 * - Some net-snmp builds ignore SNMP_VALUE_PLAIN and still return "TYPE: value"
 *   (and quote strings), so values are normalised via plain() either way.
 *
 * Native ext-snmp failures (\SNMPException, returned-false) are normalised to our
 * SnmpClientException. Never include the community in thrown messages.
 */
class PhpSnmpClient implements SnmpClient
{
    public function __construct(
        private int $timeoutUs = 1_000_000,
        private int $retries = 1,
    ) {}

    public function get(string $host, string $community, array $oids): array
    {
        if ($oids === []) {
            return [];
        }

        $session = $this->session($host, $community);

        try {
            $result = @$session->get($oids);
            if ($result === false) {
                throw new SnmpClientException("SNMP get failed for {$host}: ".$session->getError());
            }

            return is_array($result) ? array_map(self::plain(...), $result) : [];
        } catch (\SNMPException $e) {
            throw new SnmpClientException("SNMP get failed for {$host}: ".$e->getMessage(), 0, $e);
        } finally {
            $this->close($session);
        }
    }

    public function walk(string $host, string $community, string $baseOid): array
    {
        $session = $this->session($host, $community);

        try {
            // suffix_as_keys = true -> keys are the table index (ifIndex) relative
            // to $baseOid; default max_repetitions uses GETBULK on v2c.
            $result = @$session->walk($baseOid, true);
            if ($result === false) {
                throw new SnmpClientException("SNMP walk failed for {$host}: ".$session->getError());
            }

            return is_array($result) ? array_map(self::plain(...), $result) : [];
        } catch (\SNMPException $e) {
            throw new SnmpClientException("SNMP walk failed for {$host}: ".$e->getMessage(), 0, $e);
        } finally {
            $this->close($session);
        }
    }

    /**
     * Normalise a raw ext-snmp value to a plain scalar string, build-independently:
     * strip a leading "TYPE: " tag (e.g. "Counter64: 123" -> "123") and surrounding
     * quotes on STRING values ("ether1" -> ether1). A no-op on already-plain values.
     */
    public static function plain(string $value): string
    {
        if (preg_match('/^[A-Za-z0-9\-]+: (.*)$/s', $value, $m) === 1) {
            $value = $m[1];
        }

        if (strlen($value) >= 2 && str_starts_with($value, '"') && str_ends_with($value, '"')) {
            $value = substr($value, 1, -1);
        }

        return $value;
    }

    private function session(string $host, string $community): SNMP
    {
        $session = new SNMP(SNMP::VERSION_2c, $host, $community, $this->timeoutUs, $this->retries);
        $session->valueRetrieval = SNMP_VALUE_PLAIN;
        $session->oid_output_format = SNMP_OID_OUTPUT_NUMERIC;
        $session->enum_print = false;

        return $session;
    }

    private function close(SNMP $session): void
    {
        try {
            $session->close();
        } catch (Throwable) {
            // already closed / never opened - nothing to clean up.
        }
    }
}
