<?php

namespace App\Services\Polling;

use App\Models\Device;
use App\Services\Snmp\SnmpClient;
use App\Services\Snmp\SnmpClientException;

/**
 * CPU / memory / temperature over SNMP, driven by a per-vendor OID profile
 * (see DeviceMetricProfiles + config('mymate.device_metrics.profiles')). Each metric is
 * best-effort and independent: an OID the agent doesn't implement just leaves that metric
 * null rather than failing the whole read. A transport failure (timeout/filtered) throws
 * so the orchestrator can isolate the device.
 */
class SnmpDeviceMetricsDriver implements DeviceMetricsDriver
{
    public function __construct(
        private SnmpClient $snmp,
        private DeviceMetricProfiles $profiles,
    ) {}

    public function sample(Device $device): DeviceMetrics
    {
        [$host, $community] = $this->target($device);
        $profile = $this->profiles->for($device);

        return new DeviceMetrics(
            cpuPct: DeviceMetrics::clampPct($this->cpu($host, $community, $profile)),
            memUsedPct: DeviceMetrics::clampPct($this->memory($host, $community, $profile)),
            tempC: $this->temperature($host, $community, $profile),
        );
    }

    /** @param array<string, mixed> $profile */
    private function cpu(string $host, string $community, array $profile): ?float
    {
        if (! empty($profile['cpu_walk'])) {
            $loads = $this->numericValues($this->snmp->walk($host, $community, (string) $profile['cpu_walk']));

            return $loads === [] ? null : array_sum($loads) / count($loads); // average across cores
        }

        foreach ((array) ($profile['cpu_oids'] ?? []) as $oid) {
            $res = $this->snmp->get($host, $community, [$oid]);
            $val = $this->firstNumeric($res);
            if ($val !== null) {
                return $val;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $profile */
    private function memory(string $host, string $community, array $profile): ?float
    {
        return match ($profile['mem'] ?? null) {
            'hrstorage' => $this->hrStorageMemory($host, $community),
            'cisco' => $this->ciscoMemory($host, $community, $profile),
            default => null,
        };
    }

    /**
     * Host-resources-MIB memory: walk the storage table, pick the physical-RAM row
     * (largest size among memory rows, skipping virtual/swap/cache), used/size %.
     */
    private function hrStorageMemory(string $host, string $community): ?float
    {
        $oids = config('mymate.device_metrics.hrstorage', []);
        $descr = $this->snmp->walk($host, $community, (string) $oids['descr']);
        $size = $this->numericValues($this->snmp->walk($host, $community, (string) $oids['size']));
        $used = $this->numericValues($this->snmp->walk($host, $community, (string) $oids['used']));

        $bestIndex = null;
        $bestSize = 0.0;
        foreach ($descr as $index => $label) {
            $l = strtolower((string) $label);
            $isRam = str_contains($l, 'physical memory') || str_contains($l, 'real memory')
                || str_contains($l, 'main memory') || $l === 'memory'
                || (str_contains($l, 'ram') && ! str_contains($l, 'virtual'));
            $isRam = $isRam && ! str_contains($l, 'virtual') && ! str_contains($l, 'swap')
                && ! str_contains($l, 'cache') && ! str_contains($l, 'buffer');

            if ($isRam && isset($size[$index]) && $size[$index] > $bestSize) {
                $bestSize = $size[$index];
                $bestIndex = $index;
            }
        }

        if ($bestIndex === null || $bestSize <= 0 || ! isset($used[$bestIndex])) {
            return null;
        }

        return ($used[$bestIndex] / $bestSize) * 100;
    }

    /** Cisco memory pools: sum used / (used + free) across pools. */
    private function ciscoMemory(string $host, string $community, array $profile): ?float
    {
        $used = $this->numericValues($this->snmp->walk($host, $community, (string) $profile['mem_used_walk']));
        $free = $this->numericValues($this->snmp->walk($host, $community, (string) $profile['mem_free_walk']));

        $totalUsed = array_sum($used);
        $totalFree = array_sum($free);
        $total = $totalUsed + $totalFree;

        return $total > 0 ? ($totalUsed / $total) * 100 : null;
    }

    /** @param array<string, mixed> $profile */
    private function temperature(string $host, string $community, array $profile): ?float
    {
        $divisor = max(1, (int) ($profile['temp_divisor'] ?? 1));
        $values = [];

        if (! empty($profile['temp_walk'])) {
            $values = $this->numericValues($this->snmp->walk($host, $community, (string) $profile['temp_walk']));
        }
        foreach ((array) ($profile['temp_oids'] ?? []) as $oid) {
            $values = [...$values, ...$this->numericValues($this->snmp->get($host, $community, [$oid]))];
        }

        // Ignore obvious non-readings (0 / sentinel) - take the hottest real sensor.
        $values = array_filter($values, static fn (float $v): bool => $v > 0);

        return $values === [] ? null : max($values) / $divisor;
    }

    /**
     * Resolve host + decrypted community. Mirrors SnmpThroughputDriver so a metrics
     * poll and a throughput poll fail the same way on a missing community.
     *
     * @return array{0: string, 1: string}
     */
    private function target(Device $device): array
    {
        $device->loadMissing('credential');
        $community = $device->credential?->snmp_community;

        if ($community === null || $community === '') {
            throw new SnmpClientException("Device {$device->id} ({$device->name}) has no SNMP community.");
        }

        return [$device->mgmt_ip, $community];
    }

    /**
     * Keep only numeric SNMP values, cast to float, preserving keys.
     *
     * @param  array<string, string>  $values
     * @return array<string, float>
     */
    private function numericValues(array $values): array
    {
        $out = [];
        foreach ($values as $index => $value) {
            if (is_numeric($value)) {
                $out[$index] = (float) $value;
            }
        }

        return $out;
    }

    /** @param array<string, string> $values */
    private function firstNumeric(array $values): ?float
    {
        foreach ($values as $value) {
            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }
}
