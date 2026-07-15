<?php

namespace App\Services\Snmp;

use App\Models\Credential;

/**
 * Everything the SNMP transport needs to authenticate to a host: a v1/v2c community string, or
 * the full v3 USM parameter set (user, security level, auth + privacy protocols/passphrases).
 * The driver plumbing carries this around instead of a bare community string, so SNMPv3 works
 * end to end without every call site knowing the version.
 */
final readonly class SnmpCredential
{
    public function __construct(
        public string $version = '2c',        // '1' | '2c' | '3'
        public string $community = '',        // v1 / v2c
        public string $secName = '',          // v3 USM user
        public string $secLevel = 'authPriv', // noAuthNoPriv | authNoPriv | authPriv
        public string $authProtocol = 'SHA',  // MD5 | SHA | SHA-224 | SHA-256 | SHA-384 | SHA-512
        public string $authPassphrase = '',
        public string $privProtocol = 'AES',  // DES | AES | AES-192 | AES-256
        public string $privPassphrase = '',
    ) {}

    /** Build from a stored credential; null/other-type credentials yield an unusable v2c stub. */
    public static function fromCredential(?Credential $c): self
    {
        if ($c === null) {
            return new self(community: '');
        }

        $version = $c->snmp_version ?: '2c';

        if ($version === '3') {
            return new self(
                version: '3',
                secName: (string) $c->snmp_sec_name,
                secLevel: $c->snmp_sec_level ?: 'authPriv',
                authProtocol: $c->snmp_auth_protocol ?: 'SHA',
                authPassphrase: (string) $c->snmp_auth_passphrase,
                privProtocol: $c->snmp_priv_protocol ?: 'AES',
                privPassphrase: (string) $c->snmp_priv_passphrase,
            );
        }

        return new self(version: $version, community: (string) $c->snmp_community);
    }

    /**
     * Enough to actually authenticate? v1/v2c need a community; v3 needs a user, plus an auth
     * passphrase unless noAuthNoPriv, plus a privacy passphrase when authPriv.
     */
    public function isUsable(): bool
    {
        if ($this->version === '3') {
            if ($this->secName === '') {
                return false;
            }
            if ($this->secLevel !== 'noAuthNoPriv' && $this->authPassphrase === '') {
                return false;
            }
            if ($this->secLevel === 'authPriv' && $this->privPassphrase === '') {
                return false;
            }

            return true;
        }

        return $this->community !== '';
    }
}
