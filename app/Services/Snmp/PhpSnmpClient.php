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

    public function get(string $host, SnmpCredential $cred, array $oids): array
    {
        if ($oids === []) {
            return [];
        }

        $session = $this->session($host, $cred);

        try {
            $result = @$session->get($oids);
            if ($result === false) {
                $err = $session->getError();
                // A device that answers "no such object/instance" for an OID it doesn't
                // implement is reachable - that's an absent value, not a transport failure.
                // Return empty so a best-effort caller (metrics) reads null instead of the
                // whole poll aborting; only real transport errors (timeout/filtered) throw.
                if (self::isAbsence($err)) {
                    return [];
                }
                throw new SnmpClientException("SNMP get failed for {$host}: ".$err);
            }

            return is_array($result) ? array_map(self::plain(...), $result) : [];
        } catch (\SNMPException $e) {
            if (self::isAbsence($e->getMessage())) {
                return [];
            }
            throw new SnmpClientException("SNMP get failed for {$host}: ".$e->getMessage(), 0, $e);
        } finally {
            $this->close($session);
        }
    }

    public function walk(string $host, SnmpCredential $cred, string $baseOid): array
    {
        $session = $this->session($host, $cred);

        try {
            // suffix_as_keys = true -> keys are the table index (ifIndex) relative
            // to $baseOid; default max_repetitions uses GETBULK on v2c.
            $result = @$session->walk($baseOid, true);
            if ($result === false) {
                $err = $session->getError();
                // An empty subtree (nonexistent table / past the end of the MIB) is a valid
                // "nothing here" answer, not a transport failure - return an empty table.
                if (self::isAbsence($err)) {
                    return [];
                }
                throw new SnmpClientException("SNMP walk failed for {$host}: ".$err);
            }

            return is_array($result) ? array_map(self::plain(...), $result) : [];
        } catch (\SNMPException $e) {
            if (self::isAbsence($e->getMessage())) {
                return [];
            }
            throw new SnmpClientException("SNMP walk failed for {$host}: ".$e->getMessage(), 0, $e);
        } finally {
            $this->close($session);
        }
    }

    /**
     * True when an ext-snmp error means the agent answered that an OID/subtree is absent
     * (device reachable) rather than a transport failure (timeout / filtered / auth). These
     * are the standard net-snmp phrasings for the noSuchObject / noSuchInstance / endOfMibView
     * PDU exceptions - a best-effort read should treat them as "no value", not a failed poll.
     */
    private static function isAbsence(string $error): bool
    {
        $e = strtolower($error);

        return str_contains($e, 'no such object')
            || str_contains($e, 'no such instance')
            || str_contains($e, 'no more variables left') // "...past the end of the MIB tree"
            || str_contains($e, 'end of mib');
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

    private function session(string $host, SnmpCredential $cred): SNMP
    {
        if ($cred->version === '3') {
            // v3: the "community" slot carries the USM security (user) name; credentials are then
            // applied with setSecurity. Only pass the auth/priv params the level actually uses so
            // net-snmp doesn't choke on empty protocol strings.
            $session = new SNMP(SNMP::VERSION_3, $host, $cred->secName, $this->timeoutUs, $this->retries);
            if ($cred->secLevel === 'noAuthNoPriv') {
                $session->setSecurity('noAuthNoPriv');
            } elseif ($cred->secLevel === 'authNoPriv') {
                $session->setSecurity('authNoPriv', $cred->authProtocol, $cred->authPassphrase);
            } else {
                $session->setSecurity('authPriv', $cred->authProtocol, $cred->authPassphrase, $cred->privProtocol, $cred->privPassphrase);
            }
        } else {
            $version = $cred->version === '1' ? SNMP::VERSION_1 : SNMP::VERSION_2c;
            $session = new SNMP($version, $host, $cred->community, $this->timeoutUs, $this->retries);
        }

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
