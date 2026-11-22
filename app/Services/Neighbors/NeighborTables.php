<?php

namespace App\Services\Neighbors;

/**
 * The raw neighbour tables, in one shape whether this server read them or a remote agent did:
 *
 *  - `walks`:    column OID => [row index suffix => value], for the OIDs in WALKS
 *  - `scalars`:  OID => value, for the OIDs in SCALARS
 *  - `routeros`: the rows of RouterOS's /ip/neighbor/print, as the API returns them
 *
 * Only standard MIBs (LLDP-MIB) and Cisco's CDP cache are walked, so the agent just reads these
 * OIDs and all the parsing lives here (parse()). An agent sends binary octet strings as
 * "hex:<hex>"; this server's own SNMP client returns them raw. Octets::bytes() takes either.
 */
final class NeighborTables
{
    public const LLDP_LOC_CHASSIS_ID = '1.0.8802.1.1.2.1.3.2.0';

    public const LLDP_LOC_PORT_ID = '1.0.8802.1.1.2.1.3.7.1.3';

    public const LLDP_LOC_PORT_DESC = '1.0.8802.1.1.2.1.3.7.1.4';

    public const LLDP_REM_CHASSIS_ID = '1.0.8802.1.1.2.1.4.1.1.5';

    public const LLDP_REM_PORT_ID_SUBTYPE = '1.0.8802.1.1.2.1.4.1.1.6';

    public const LLDP_REM_PORT_ID = '1.0.8802.1.1.2.1.4.1.1.7';

    public const LLDP_REM_PORT_DESC = '1.0.8802.1.1.2.1.4.1.1.8';

    public const LLDP_REM_SYS_NAME = '1.0.8802.1.1.2.1.4.1.1.9';

    /** lldpRemManAddrIfSubtype: the value is unimportant, the address is in the row index. */
    public const LLDP_REM_MAN_ADDR = '1.0.8802.1.1.2.1.4.2.1.3';

    public const CDP_ADDRESS_TYPE = '1.3.6.1.4.1.9.9.23.1.2.1.1.3';

    public const CDP_ADDRESS = '1.3.6.1.4.1.9.9.23.1.2.1.1.4';

    public const CDP_DEVICE_ID = '1.3.6.1.4.1.9.9.23.1.2.1.1.6';

    public const CDP_DEVICE_PORT = '1.3.6.1.4.1.9.9.23.1.2.1.1.7';

    public const WALKS = [
        self::LLDP_LOC_PORT_ID, self::LLDP_LOC_PORT_DESC,
        self::LLDP_REM_CHASSIS_ID, self::LLDP_REM_PORT_ID_SUBTYPE, self::LLDP_REM_PORT_ID,
        self::LLDP_REM_PORT_DESC, self::LLDP_REM_SYS_NAME, self::LLDP_REM_MAN_ADDR,
        self::CDP_ADDRESS_TYPE, self::CDP_ADDRESS, self::CDP_DEVICE_ID, self::CDP_DEVICE_PORT,
    ];

    public const SCALARS = [self::LLDP_LOC_CHASSIS_ID];

    /** lldpRemPortIdSubtype macAddress(3): the port id is a MAC, no use as a port name. */
    private const PORT_ID_MAC = 3;

    /**
     * @param  array<string, array<string, string>>  $walks
     * @param  array<string, string>  $scalars
     * @param  list<array<string, string>>  $routeros
     */
    public function __construct(
        public readonly array $walks = [],
        public readonly array $scalars = [],
        public readonly array $routeros = [],
    ) {}

    /**
     * From an agent's payload (or anything shaped like it). Tolerant: a missing or odd key is
     * just empty (agent/COMPATIBILITY.md).
     *
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(array $raw): self
    {
        $walks = [];
        foreach ((array) ($raw['walks'] ?? []) as $oid => $rows) {
            if (in_array($oid, self::WALKS, true) && is_array($rows)) {
                $walks[$oid] = array_map('strval', array_filter($rows, 'is_scalar'));
            }
        }
        $scalars = array_map('strval', array_filter(
            array_intersect_key((array) ($raw['scalars'] ?? []), array_flip(self::SCALARS)),
            'is_scalar',
        ));
        $routeros = [];
        foreach ((array) ($raw['routeros'] ?? []) as $row) {
            if (is_array($row)) {
                $routeros[] = array_map('strval', array_filter($row, 'is_scalar'));
            }
        }

        return new self($walks, $scalars, $routeros);
    }

    /** This device's own LLDP chassis id, normalised (a MAC as aa:bb:..), or null. */
    public function localChassisId(): ?string
    {
        return Octets::id($this->scalars[self::LLDP_LOC_CHASSIS_ID] ?? null);
    }

    /** @return list<Neighbor> */
    public function parse(): array
    {
        return [...$this->lldp(), ...$this->cdp(), ...$this->routerOs()];
    }

    /** @return list<Neighbor> */
    private function lldp(): array
    {
        $w = fn (string $oid): array => $this->walks[$oid] ?? [];
        $locId = $w(self::LLDP_LOC_PORT_ID);
        $locDesc = $w(self::LLDP_LOC_PORT_DESC);
        $chassis = $w(self::LLDP_REM_CHASSIS_ID);
        $subtype = $w(self::LLDP_REM_PORT_ID_SUBTYPE);
        $portId = $w(self::LLDP_REM_PORT_ID);
        $portDesc = $w(self::LLDP_REM_PORT_DESC);
        $sysName = $w(self::LLDP_REM_SYS_NAME);

        // Management addresses: index timeMark.localPort.remIndex.addrSubtype.addrLen.addr...,
        // IPv4 (subtype 1, length 4) only.
        $mgmt = [];
        foreach (array_keys($w(self::LLDP_REM_MAN_ADDR)) as $suffix) {
            $p = explode('.', (string) $suffix);
            if (count($p) === 9 && $p[3] === '1' && $p[4] === '4') {
                $mgmt["{$p[0]}.{$p[1]}.{$p[2]}"] ??= "{$p[5]}.{$p[6]}.{$p[7]}.{$p[8]}";
            }
        }

        $out = [];
        // Index of every lldpRemTable column: timeMark.localPortNum.remIndex.
        $rows = array_unique([...array_keys($chassis), ...array_keys($sysName), ...array_keys($portId)]);
        foreach ($rows as $row) {
            $p = explode('.', (string) $row);
            if (count($p) !== 3) {
                continue;
            }
            $localNum = (int) $p[1];
            $remotePorts = [];
            if ((int) ($subtype[$row] ?? 0) !== self::PORT_ID_MAC) {
                $remotePorts[] = Octets::text($portId[$row] ?? null);
            }
            $remotePorts[] = Octets::text($portDesc[$row] ?? null);

            $out[] = new Neighbor(
                protocol: 'lldp',
                localPorts: self::names([Octets::text($locId[(string) $localNum] ?? null), Octets::text($locDesc[(string) $localNum] ?? null)]),
                localPortNum: $localNum,
                remoteChassis: Octets::id($chassis[$row] ?? null),
                remoteName: Octets::text($sysName[$row] ?? null),
                remotePorts: self::names($remotePorts),
                remoteIp: $mgmt[$row] ?? null,
            );
        }

        return $out;
    }

    /** @return list<Neighbor> */
    private function cdp(): array
    {
        $w = fn (string $oid): array => $this->walks[$oid] ?? [];
        $type = $w(self::CDP_ADDRESS_TYPE);
        $addr = $w(self::CDP_ADDRESS);
        $port = $w(self::CDP_DEVICE_PORT);

        $out = [];
        // Index: ifIndex.deviceIndex - the local ifIndex is given outright.
        foreach ($w(self::CDP_DEVICE_ID) as $row => $deviceId) {
            $p = explode('.', (string) $row);
            if (count($p) !== 2) {
                continue;
            }
            $ip = null;
            $bytes = Octets::bytes($addr[$row] ?? null);
            if (($type[$row] ?? '1') === '1' && $bytes !== null && strlen($bytes) === 4) {
                $ip = implode('.', array_map('ord', str_split($bytes)));
            }
            // Cisco puts the serial in brackets after the hostname: "SW1(FOC1234X0YZ)".
            $name = preg_replace('/\(.*\)$/', '', (string) Octets::text($deviceId));

            $out[] = new Neighbor(
                protocol: 'cdp',
                localIfIndex: (int) $p[0],
                remoteName: $name !== '' ? $name : null,
                remotePorts: self::names([Octets::text($port[$row] ?? null)]),
                remoteIp: $ip,
            );
        }

        return $out;
    }

    /** @return list<Neighbor> */
    private function routerOs(): array
    {
        $out = [];
        foreach ($this->routeros as $row) {
            // "ether1,bridge" when heard on a bridge port: the physical port comes first.
            $local = explode(',', (string) ($row['interface'] ?? ''))[0];
            $by = explode(',', (string) ($row['discovered-by'] ?? 'mndp'));
            $ip = (string) ($row['address'] ?? $row['address4'] ?? '');

            $out[] = new Neighbor(
                protocol: in_array('mndp', $by, true) || ! in_array($by[0], ['lldp', 'cdp'], true) ? 'mndp' : $by[0],
                localPorts: self::names([$local]),
                remoteChassis: Octets::id($row['mac-address'] ?? null),
                remoteName: ($row['identity'] ?? '') !== '' ? $row['identity'] : null,
                remotePorts: self::names([$row['interface-name'] ?? null]),
                remoteIp: filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null,
            );
        }

        return $out;
    }

    /**
     * @param  list<?string>  $names
     * @return list<string>
     */
    private static function names(array $names): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (?string $n) => $n === null ? '' : trim($n),
            $names,
        ), fn (string $n) => $n !== '')));
    }
}
