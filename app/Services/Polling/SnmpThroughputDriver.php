<?php

namespace App\Services\Polling;

use App\Models\Device;
use App\Services\Snmp\SnmpClient;
use App\Services\Snmp\SnmpClientException;
use App\Services\Snmp\SnmpCredential;

/**
 * Throughput via SNMP, 64-bit ifXTable counters where the box has them.
 *
 * discover(): ifName (fallback ifDescr) + ifHighSpeed (Mbps capacity).
 * sample():   ifHCInOctets / ifHCOutOctets - raw counters; the delta math + the
 *             counter-reset guard live in RateCalculator, applied by the action.
 *             SNMPv1 (or a box with no HC octets) gets ifInOctets / ifOutOctets instead,
 *             flagged as 32-bit so the rate maths allows for the wrap.
 *
 * Walk keys are the ifIndex (SnmpClient returns suffix-as-keys).
 */
class SnmpThroughputDriver implements PortStatsDriver, ThroughputDriver
{
    public function __construct(private SnmpClient $snmp) {}

    public function discover(Device $device): array
    {
        [$host, $community] = $this->target($device);
        $oids = $this->oids();

        $names = $this->snmp->walk($host, $community, $oids['if_name']);
        if ($names === []) {
            // Some agents leave ifName empty; fall back to the classic ifDescr.
            $names = $this->snmp->walk($host, $community, $oids['if_descr']);
        }
        $speeds = $this->snmp->walk($host, $community, $oids['if_high_speed']);
        // ifAlias = the operator-set port description (best-effort; empty on most ports).
        $aliases = isset($oids['if_alias']) ? $this->snmp->walk($host, $community, $oids['if_alias']) : [];

        $interfaces = [];
        foreach ($names as $index => $name) {
            $ifIndex = (int) $index;
            $name = trim((string) $name);
            if ($name === '') {
                $name = "if{$ifIndex}";
            }

            $description = isset($aliases[$index]) ? trim((string) $aliases[$index]) : '';

            $interfaces[] = [
                'if_index' => $ifIndex,
                'name' => $name,
                'description' => $description !== '' ? $description : null,
                'speed_mbps' => isset($speeds[$index]) ? (int) $speeds[$index] : null,
            ];
        }

        return $interfaces;
    }

    public function sample(Device $device): array
    {
        [$host, $community] = $this->target($device);
        $oids = $this->oids();

        // SNMPv1 can't carry Counter64 (a v1 agent just skips the ifXTable columns on a walk),
        // so go straight to the 32-bit ifTable octets there. A v2c/v3 box with no HC counters at
        // all falls back to them too.
        $narrow = $community->version === '1';
        $in = $out = [];
        if (! $narrow) {
            $in = $this->snmp->walk($host, $community, $oids['if_hc_in_octets']);
            $out = $this->snmp->walk($host, $community, $oids['if_hc_out_octets']);
            $narrow = $in === [];
        }
        if ($narrow && isset($oids['if_in_octets'], $oids['if_out_octets'])) {
            $in = $this->snmp->walk($host, $community, $oids['if_in_octets']);
            $out = $this->snmp->walk($host, $community, $oids['if_out_octets']);
        }
        // ifOperStatus (best-effort): 1=up, everything else (down/testing/dormant/...) is "not up".
        // An OID a device doesn't answer just leaves oper null (unknown) for that port.
        $oper = isset($oids['if_oper_status'])
            ? $this->snmp->walk($host, $community, $oids['if_oper_status'])
            : [];
        $ts = microtime(true);

        $samples = [];
        foreach ($in as $index => $inOctets) {
            if (! isset($out[$index])) {
                continue; // need both directions to be useful.
            }

            $operUp = isset($oper[$index]) && is_numeric($oper[$index]) ? ((int) $oper[$index] === 1) : null;
            $samples[(int) $index] = InterfaceSample::counters((int) $inOctets, (int) $out[$index], $ts, $operUp, $narrow);
        }

        return $samples;
    }

    /**
     * Errors, discards and packets per port, by GET on the ifIndexes we already know rather than
     * ten column walks. A walk pays for every ifIndex the box has (VLANs, tunnels, the lot); a
     * GET only asks about ports we store, packed `snmp.get_chunk` OIDs to a PDU. Packets are
     * unicast + multicast + broadcast summed, from the 64-bit ifXTable counters.
     *
     * A port whose HC unicast column doesn't answer gets its packets from the 32-bit ifTable
     * instead (unicast + non-unicast), in a second GET for just those ports, and a v1 box is
     * asked for those straight away: v1 can't carry Counter64 and a v1 agent fails the whole PDU
     * on one (ext-snmp then retries it an OID at a time). The 32-bit sum is kept inside 32 bits
     * and handed back under the PortStats::NARROW name, so it gets the Counter32 wrap handling.
     *
     * Absent OIDs just come back missing and that counter stays null. Only a transport failure
     * throws, and the caller treats that as "no port stats this time", the octets tick is
     * already done by then.
     */
    public function portCounters(Device $device, array $ifIndexes): array
    {
        if ($ifIndexes === []) {
            return [];
        }
        [$host, $community] = $this->target($device);
        $ifIndexes = array_map('intval', $ifIndexes);

        // counter name => the columns that add up to it
        $columns = [
            'errors_in' => ['if_in_errors'],
            'errors_out' => ['if_out_errors'],
            'discards_in' => ['if_in_discards'],
            'discards_out' => ['if_out_discards'],
        ];
        $wide = [
            'pkts_in' => ['if_hc_in_ucast_pkts', 'if_hc_in_mcast_pkts', 'if_hc_in_bcast_pkts'],
            'pkts_out' => ['if_hc_out_ucast_pkts', 'if_hc_out_mcast_pkts', 'if_hc_out_bcast_pkts'],
        ];
        $narrow = [
            PortStats::NARROW['pkts_in'] => ['if_in_ucast_pkts', 'if_in_nucast_pkts'],
            PortStats::NARROW['pkts_out'] => ['if_out_ucast_pkts', 'if_out_nucast_pkts'],
        ];
        $v1 = $community->version === '1';
        $columns = [...$columns, ...($v1 ? $narrow : $wide)];

        $out = $this->readColumns($host, $community, $columns, $ifIndexes);

        if (! $v1) {
            // second pass, only for the ports (and directions) the HC columns didn't answer
            $missing = [];
            foreach ($ifIndexes as $ifIndex) {
                $want = [];
                foreach (PortStats::NARROW as $name => $narrowName) {
                    if (! isset($out[$ifIndex][$name])) {
                        $want[$narrowName] = $narrow[$narrowName];
                    }
                }
                if ($want !== []) {
                    $missing[$ifIndex] = $want;
                }
            }
            if ($missing !== []) {
                $more = $this->readColumns($host, $community, array_merge(...array_values($missing)), array_keys($missing));
                foreach ($more as $ifIndex => $port) {
                    $out[$ifIndex] = [...($out[$ifIndex] ?? []), ...array_intersect_key($port, $missing[$ifIndex])];
                }
            }
        }

        foreach ($out as $ifIndex => $port) {
            foreach (PortStats::NARROW as $narrowName) {
                if (isset($port[$narrowName])) {
                    $out[$ifIndex][$narrowName] = PortStats::wrap32($port[$narrowName]);
                }
            }
        }

        return $out;
    }

    /**
     * GET the given column sets for the given ports, `snmp.get_chunk` OIDs to a PDU, and sum each
     * set per port. The first column of a set has to be there (unicast for packets), a lone
     * broadcast count isn't "packets" and would read as a drop when unicast comes back.
     *
     * @param  array<string, list<string>>  $columns  counter name => config oid keys
     * @param  list<int>  $ifIndexes
     * @return array<int, array<string, int>>
     */
    private function readColumns(string $host, SnmpCredential $community, array $columns, array $ifIndexes): array
    {
        $oids = $this->oids();

        $wanted = [];
        foreach ($ifIndexes as $ifIndex) {
            foreach ($columns as $cols) {
                foreach ($cols as $col) {
                    if (isset($oids[$col])) {
                        $wanted[] = ltrim($oids[$col], '.').'.'.$ifIndex;
                    }
                }
            }
        }

        $values = [];
        $chunk = max(1, (int) config('mymate.snmp.get_chunk', 40));
        foreach (array_chunk($wanted, $chunk) as $batch) {
            foreach ($this->snmp->get($host, $community, array_map(static fn (string $o): string => '.'.$o, $batch)) as $oid => $value) {
                if (is_numeric($value)) {
                    $values[ltrim((string) $oid, '.')] = (int) $value;
                }
            }
        }

        $out = [];
        foreach ($ifIndexes as $ifIndex) {
            $port = [];
            foreach ($columns as $name => $cols) {
                $parts = [];
                foreach ($cols as $col) {
                    $key = ltrim($oids[$col] ?? '', '.').'.'.$ifIndex;
                    if (isset($values[$key])) {
                        $parts[$col] = $values[$key];
                    }
                }
                if (isset($parts[$cols[0]])) {
                    $port[$name] = array_sum($parts);
                }
            }
            if ($port !== []) {
                $out[$ifIndex] = $port;
            }
        }

        return $out;
    }

    /**
     * Resolve the host + decrypted community for this device.
     *
     * @return array{0: string, 1: SnmpCredential}
     */
    private function target(Device $device): array
    {
        $device->loadMissing('credential');
        $cred = SnmpCredential::fromCredential($device->credential);

        if (! $cred->isUsable()) {
            throw new SnmpClientException("Device {$device->id} ({$device->name}) has no usable SNMP credential.");
        }

        return [$device->mgmt_ip, $cred];
    }

    /** @return array<string, string> */
    private function oids(): array
    {
        /** @var array<string, string> $oids */
        $oids = config('mymate.snmp.oids', []);

        return $oids;
    }
}
