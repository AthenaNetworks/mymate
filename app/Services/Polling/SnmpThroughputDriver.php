<?php

namespace App\Services\Polling;

use App\Models\Device;
use App\Services\Snmp\SnmpClient;
use App\Services\Snmp\SnmpClientException;
use App\Services\Snmp\SnmpCredential;

/**
 * Throughput via SNMP v2c, 64-bit ifXTable counters.
 *
 * discover(): ifName (fallback ifDescr) + ifHighSpeed (Mbps capacity).
 * sample():   ifHCInOctets / ifHCOutOctets - raw counters; the delta math + the
 *             counter-reset guard live in RateCalculator, applied by the action.
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

        $in = $this->snmp->walk($host, $community, $oids['if_hc_in_octets']);
        $out = $this->snmp->walk($host, $community, $oids['if_hc_out_octets']);
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
            $samples[(int) $index] = InterfaceSample::counters((int) $inOctets, (int) $out[$index], $ts, $operUp);
        }

        return $samples;
    }

    /**
     * Errors, discards and packets per port, by GET on the ifIndexes we already know rather than
     * ten column walks. A walk pays for every ifIndex the box has (VLANs, tunnels, the lot); a
     * GET only asks about ports we store, packed `snmp.get_chunk` OIDs to a PDU. Packets are
     * unicast + multicast + broadcast summed, from the 64-bit ifXTable counters.
     *
     * Absent OIDs (v1 has no Counter64, some boxes skip ifXTable packets) just come back missing
     * and that counter stays null. Only a transport failure throws, and the caller treats that
     * as "no port stats this time", the octets tick is already done by then.
     */
    public function portCounters(Device $device, array $ifIndexes): array
    {
        if ($ifIndexes === []) {
            return [];
        }
        [$host, $community] = $this->target($device);
        $oids = $this->oids();

        // counter name => the columns that add up to it
        $columns = [
            'errors_in' => ['if_in_errors'],
            'errors_out' => ['if_out_errors'],
            'discards_in' => ['if_in_discards'],
            'discards_out' => ['if_out_discards'],
            'pkts_in' => ['if_hc_in_ucast_pkts', 'if_hc_in_mcast_pkts', 'if_hc_in_bcast_pkts'],
            'pkts_out' => ['if_hc_out_ucast_pkts', 'if_hc_out_mcast_pkts', 'if_hc_out_bcast_pkts'],
        ];

        // SNMPv1 can't carry Counter64, and a v1 agent fails the whole PDU on one of them (ext-snmp
        // then retries without it, one OID at a time), so don't ask a v1 box for the HC packets.
        $v1 = $community->version === '1';

        $wanted = [];
        foreach ($ifIndexes as $ifIndex) {
            foreach ($columns as $cols) {
                foreach ($cols as $col) {
                    if (isset($oids[$col]) && ! ($v1 && str_starts_with($col, 'if_hc_'))) {
                        $wanted[] = ltrim($oids[$col], '.').'.'.(int) $ifIndex;
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
                    $key = ltrim($oids[$col] ?? '', '.').'.'.(int) $ifIndex;
                    if (isset($values[$key])) {
                        $parts[$col] = $values[$key];
                    }
                }
                // the first column has to be there (unicast for packets), a lone broadcast
                // count isn't "packets" and would read as a drop when unicast comes back
                if (isset($parts[$cols[0]])) {
                    $port[$name] = array_sum($parts);
                }
            }
            if ($port !== []) {
                $out[(int) $ifIndex] = $port;
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
