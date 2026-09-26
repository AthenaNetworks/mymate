<?php

namespace App\Services\Polling;

use App\Models\Device;
use App\Services\Snmp\SnmpClient;
use App\Services\Snmp\SnmpClientException;
use App\Services\Snmp\SnmpCredential;

/**
 * CPU / memory / temperature (plus per-CPU load, storage and uptime for the device page) over
 * SNMP, driven by a per-vendor OID profile
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

        $wl = $this->wireless($host, $community, $profile);
        [$cpu, $cpuLoads] = $this->cpu($host, $community, $profile);
        $hr = $this->hrStorage($host, $community, $profile);

        return new DeviceMetrics(
            cpuPct: DeviceMetrics::clampPct($cpu),
            memUsedPct: DeviceMetrics::clampPct(($profile['mem'] ?? null) === 'hrstorage' ? $hr['mem'] : $this->memory($host, $community, $profile)),
            tempC: $this->temperature($host, $community, $profile),
            signalDbm: $wl['signal'],
            snrDb: $wl['snr'],
            ccqPct: DeviceMetrics::clampPct($wl['ccq']),
            wirelessClients: $wl['clients'],
            uptimeSeconds: $this->uptime($host, $community),
            cpuLoads: $cpuLoads,
            storages: $hr['storages'],
        );
    }

    /**
     * Host uptime in seconds: hrSystemUptime, else sysUpTime, in one GET. hrSystemUptime is the
     * machine, sysUpTime only the SNMP agent (it resets when snmpd restarts, which would look
     * like a reboot), so the host one wins when a box has both.
     */
    private function uptime(string $host, SnmpCredential $community): ?int
    {
        $oids = config('mymate.snmp.oids', []);
        $hrOid = (string) ($oids['hr_system_uptime'] ?? '');
        $sysOid = (string) ($oids['sys_uptime'] ?? '');
        $res = [];
        foreach ($this->snmp->get($host, $community, array_values(array_filter([$hrOid, $sysOid]))) as $oid => $value) {
            $res[ltrim((string) $oid, '.')] = $value;
        }

        foreach ([$hrOid, $sysOid] as $oid) {
            $ticks = self::timeticks($res[ltrim($oid, '.')] ?? null);
            if ($oid !== '' && $ticks !== null) {
                return intdiv($ticks, 100);
            }
        }

        return null;
    }

    /** TimeTicks as a plain number, or the "(12345) 0:02:03.45" form some builds hand back. */
    public static function timeticks(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        $v = trim((string) $value);
        if (preg_match('/^\((\d+)\)/', $v, $m) === 1) {
            return (int) $m[1];
        }

        return ctype_digit($v) ? (int) $v : null;
    }

    /**
     * The hrStorageTable, walked once as a whole entry: that gives the per-entry storage list
     * (disks, RAM, swap) and the memory % from the same rows. A profile can opt out with
     * 'storage' => false. When the entry walk comes back empty and the profile reads memory from
     * hrstorage, fall back to the old per-column walks, so an agent that's funny about walking
     * the entry still gets its memory graph.
     *
     * @param  array<string, mixed>  $profile
     * @return array{mem: ?float, storages: ?list<StorageReading>}
     */
    private function hrStorage(string $host, SnmpCredential $community, array $profile): array
    {
        $memFromHr = ($profile['mem'] ?? null) === 'hrstorage';
        if (($profile['storage'] ?? true) === false) {
            return ['mem' => $memFromHr ? $this->hrStorageMemory($host, $community) : null, 'storages' => null];
        }

        $oids = config('mymate.device_metrics.hrstorage', []);
        $entry = ! empty($oids['entry']) ? $this->snmp->walk($host, $community, (string) $oids['entry']) : [];
        if ($entry !== []) {
            $cols = StorageReading::entryColumns($entry);

            return [
                'mem' => $memFromHr ? self::ramPercent($cols[3], $this->numericValues($cols[5]), $this->numericValues($cols[6])) : null,
                'storages' => StorageReading::fromHrColumns($cols[3], $cols[2], $cols[4], $cols[5], $cols[6]),
            ];
        }
        if (! $memFromHr) {
            return ['mem' => null, 'storages' => []];
        }

        $descr = $this->snmp->walk($host, $community, (string) $oids['descr']);
        $size = $this->snmp->walk($host, $community, (string) $oids['size']);
        $used = $this->snmp->walk($host, $community, (string) $oids['used']);
        if ($descr === []) {
            return ['mem' => null, 'storages' => []];
        }
        $types = ! empty($oids['type']) ? $this->snmp->walk($host, $community, (string) $oids['type']) : [];
        $units = ! empty($oids['units']) ? $this->snmp->walk($host, $community, (string) $oids['units']) : [];

        return [
            'mem' => self::ramPercent($descr, $this->numericValues($size), $this->numericValues($used)),
            'storages' => StorageReading::fromHrColumns($descr, $types, $units, $size, $used),
        ];
    }

    /**
     * Wireless RF over SNMP, driven by optional profile OIDs (a profile without them just
     * leaves every field null). Each metric can be read as a scalar GET or a table walk, and
     * both are supported so the one profile covers a device in AP mode (RF is a per-station
     * table -> averaged) and station/CPE mode (RF is a scalar):
     *   signal|snr|ccq _oids   GET scalars, all numeric averaged
     *   signal|snr|ccq _walk   walk column(s), all numeric averaged
     *   clients_walk           walk column(s), COUNT the rows (one per associated station)
     *   clients_value_walk     walk/GET, take the reported count VALUE (summed across rows)
     *
     * @param  array<string, mixed>  $profile
     * @return array{signal:?float, snr:?float, ccq:?float, clients:?int}
     */
    private function wireless(string $host, SnmpCredential $community, array $profile): array
    {
        return [
            'signal' => $this->rfMeasure($host, $community, $profile['signal_oids'] ?? [], $profile['signal_walk'] ?? []),
            'snr' => $this->rfMeasure($host, $community, $profile['snr_oids'] ?? [], $profile['snr_walk'] ?? []),
            'ccq' => $this->rfMeasure($host, $community, $profile['ccq_oids'] ?? [], $profile['ccq_walk'] ?? []),
            'clients' => $this->rfClients($host, $community, $profile),
        ];
    }

    /**
     * Average of every numeric value from the scalar GETs plus the table walks (an empty set
     * -> null). Averaging lets an AP report the mean across its associated stations.
     *
     * @param  string|list<string>  $oids  scalar OIDs to GET
     * @param  string|list<string>  $walks  table column OIDs to walk
     */
    private function rfMeasure(string $host, SnmpCredential $community, string|array $oids, string|array $walks): ?float
    {
        $vals = [];
        foreach ((array) $oids as $oid) {
            $v = $this->firstNumeric($this->snmp->get($host, $community, [$oid]));
            if ($v !== null) {
                $vals[] = $v;
            }
        }
        foreach ((array) $walks as $oid) {
            foreach ($this->numericValues($this->snmp->walk($host, $community, $oid)) as $v) {
                $vals[] = $v;
            }
        }

        return $vals === [] ? null : round(array_sum($vals) / count($vals), 1);
    }

    /** @param array<string, mixed> $profile */
    private function rfClients(string $host, SnmpCredential $community, array $profile): ?int
    {
        // A registration/station table: one row per associated station -> count the rows.
        if (! empty($profile['clients_walk'])) {
            $count = 0;
            $any = false;
            foreach ((array) $profile['clients_walk'] as $oid) {
                $rows = $this->numericValues($this->snmp->walk($host, $community, $oid));
                if ($rows !== []) {
                    $any = true;
                    $count += count($rows);
                }
            }

            return $any ? $count : null;
        }

        // A reported count value (e.g. cambiumAPNumberOfConnectedSTA / ubntWlStatStaCount).
        if (! empty($profile['clients_value_walk'])) {
            $sum = 0.0;
            $any = false;
            foreach ((array) $profile['clients_value_walk'] as $oid) {
                foreach ($this->numericValues($this->snmp->walk($host, $community, $oid)) as $v) {
                    $sum += $v;
                    $any = true;
                }
            }

            return $any ? (int) round($sum) : null;
        }

        return null;
    }

    /**
     * Overall cpu % plus the per-processor loads it came from. Only a walked profile (the
     * hrProcessorLoad column) has per-processor values, a scalar cpu_oids reading has none.
     *
     * @param  array<string, mixed>  $profile
     * @return array{0: ?float, 1: ?array<int, float>}
     */
    private function cpu(string $host, SnmpCredential $community, array $profile): array
    {
        if (! empty($profile['cpu_walk'])) {
            $loads = $this->numericValues($this->snmp->walk($host, $community, (string) $profile['cpu_walk']));
            if ($loads === []) {
                return [null, null];
            }
            $perCpu = [];
            foreach ($loads as $index => $load) {
                $perCpu[(int) $index] = max(0.0, min(100.0, $load));
            }
            ksort($perCpu);

            return [array_sum($loads) / count($loads), $perCpu]; // average across cores
        }

        foreach ((array) ($profile['cpu_oids'] ?? []) as $oid) {
            $res = $this->snmp->get($host, $community, [$oid]);
            $val = $this->firstNumeric($res);
            if ($val !== null) {
                return [$val, null];
            }
        }

        return [null, null];
    }

    /** @param array<string, mixed> $profile */
    private function memory(string $host, SnmpCredential $community, array $profile): ?float
    {
        return match ($profile['mem'] ?? null) {
            'cisco' => $this->ciscoMemory($host, $community, $profile),
            default => null,
        };
    }

    /**
     * Host-resources-MIB memory: walk the storage table, pick the physical-RAM row
     * (largest size among memory rows, skipping virtual/swap/cache), used/size %.
     */
    private function hrStorageMemory(string $host, SnmpCredential $community): ?float
    {
        $oids = config('mymate.device_metrics.hrstorage', []);
        $descr = $this->snmp->walk($host, $community, (string) $oids['descr']);
        $size = $this->numericValues($this->snmp->walk($host, $community, (string) $oids['size']));
        $used = $this->numericValues($this->snmp->walk($host, $community, (string) $oids['used']));

        return self::ramPercent($descr, $size, $used);
    }

    /**
     * Pick the physical-RAM row (largest size among memory rows, skipping virtual/swap/cache)
     * and give used/size %.
     *
     * @param  array<string, string>  $descr
     * @param  array<string, float>  $size
     * @param  array<string, float>  $used
     */
    private static function ramPercent(array $descr, array $size, array $used): ?float
    {
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
    private function ciscoMemory(string $host, SnmpCredential $community, array $profile): ?float
    {
        $used = $this->numericValues($this->snmp->walk($host, $community, (string) $profile['mem_used_walk']));
        $free = $this->numericValues($this->snmp->walk($host, $community, (string) $profile['mem_free_walk']));

        $totalUsed = array_sum($used);
        $totalFree = array_sum($free);
        $total = $totalUsed + $totalFree;

        return $total > 0 ? ($totalUsed / $total) * 100 : null;
    }

    /** @param array<string, mixed> $profile */
    private function temperature(string $host, SnmpCredential $community, array $profile): ?float
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
