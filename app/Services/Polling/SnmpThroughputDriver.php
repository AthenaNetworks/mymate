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
class SnmpThroughputDriver implements ThroughputDriver
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
        $ts = microtime(true);

        $samples = [];
        foreach ($in as $index => $inOctets) {
            if (! isset($out[$index])) {
                continue; // need both directions to be useful.
            }

            $samples[(int) $index] = InterfaceSample::counters((int) $inOctets, (int) $out[$index], $ts);
        }

        return $samples;
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
