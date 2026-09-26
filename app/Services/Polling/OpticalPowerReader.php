<?php

namespace App\Services\Polling;

use App\Enums\PollMethod;
use App\Models\Device;
use App\Services\RouterOs\RouterOsClient;
use App\Services\RouterOs\RouterOsTarget;
use App\Services\Snmp\SnmpClient;
use App\Services\Snmp\SnmpCredential;

/**
 * Reads SFP / fibre optical Rx/Tx power for a device's ports (GitHub #11). Rides the metrics
 * cadence, not the fast throughput tick - light levels drift slowly.
 *
 *  - RouterOS API: `/interface/ethernet/monitor once` over every ethernet port. Ports with a
 *    module report sfp-rx-power / sfp-tx-power; copper ports and empty cages just don't.
 *  - SNMP: driven by the vendor profile (config mymate.device_metrics.profiles), the same way
 *    cpu/mem/temp are. A profile opts in with `optical_rx_walk` / `optical_tx_walk` (table
 *    columns indexed by ifIndex), an optional `optical_name_walk` so rows can be matched by
 *    port name, and `optical_divisor` for the unit scale. MikroTik's
 *    mtxrOpticalTable is wired in; adding another vendor whose optical table is ifIndex-keyed
 *    is just config.
 *
 * Returns null when optics can't be read on this device at all (no profile support, no usable
 * credential, or the read failed), and a list - possibly empty - when the read worked. The
 * difference matters: only a successful read clears a port whose module went away.
 */
class OpticalPowerReader
{
    public function __construct(
        private SnmpClient $snmp,
        private RouterOsClient $routerOs,
        private DeviceMetricProfiles $profiles,
    ) {}

    /** @return list<OpticalReading>|null */
    public function read(Device $device): ?array
    {
        try {
            return match ($device->poll_method) {
                PollMethod::Snmp => $this->viaSnmp($device),
                PollMethod::RouterOs => $this->viaRouterOs($device),
                default => null,
            };
        } catch (\Throwable) {
            // Best-effort like the rest of the metrics tick: a failed optics read keeps the last
            // values (and their optical_at) rather than wiping them.
            return null;
        }
    }

    /**
     * The SNMP optical walk spec for a device, or null when its vendor profile has none. Shared
     * with the agent dispatch so an agent-polled device reads the same OIDs.
     *
     * @return array{rx_walk?: string, tx_walk?: string, name_walk?: string, divisor: int}|null
     */
    public function snmpSpec(Device $device): ?array
    {
        $p = $this->profiles->for($device);
        $rx = (string) ($p['optical_rx_walk'] ?? '');
        $tx = (string) ($p['optical_tx_walk'] ?? '');
        if ($rx === '' && $tx === '') {
            return null;
        }

        return array_filter([
            'rx_walk' => $rx,
            'tx_walk' => $tx,
            'name_walk' => (string) ($p['optical_name_walk'] ?? ''),
        ], static fn ($v) => $v !== '') + ['divisor' => max(1, (int) ($p['optical_divisor'] ?? 1))];
    }

    /** @return list<OpticalReading>|null */
    private function viaSnmp(Device $device): ?array
    {
        $spec = $this->snmpSpec($device);
        if ($spec === null) {
            return null;
        }

        $device->loadMissing('credential');
        $cred = SnmpCredential::fromCredential($device->credential);
        if (! $cred->isUsable()) {
            return null;
        }

        $host = $device->mgmt_ip;
        $rx = isset($spec['rx_walk']) ? $this->snmp->walk($host, $cred, $spec['rx_walk']) : [];
        $tx = isset($spec['tx_walk']) ? $this->snmp->walk($host, $cred, $spec['tx_walk']) : [];
        $names = isset($spec['name_walk']) ? $this->snmp->walk($host, $cred, $spec['name_walk']) : [];

        $out = [];
        foreach (array_unique([...array_keys($rx), ...array_keys($tx)]) as $index) {
            $rxDbm = OpticalReading::dbm($rx[$index] ?? null, $spec['divisor']);
            $txDbm = OpticalReading::dbm($tx[$index] ?? null, $spec['divisor']);
            if ($rxDbm === null && $txDbm === null) {
                continue;
            }
            $name = trim((string) ($names[$index] ?? ''), " \"");
            $out[] = new OpticalReading(
                ifIndex: is_numeric($index) ? (int) $index : null,
                name: $name !== '' ? $name : null,
                rxDbm: $rxDbm,
                txDbm: $txDbm,
            );
        }

        return $out;
    }

    /** @return list<OpticalReading>|null */
    private function viaRouterOs(Device $device): ?array
    {
        $device->loadMissing('credential');
        if ($device->credential?->type !== 'routeros') {
            return null;
        }

        $conn = $this->routerOs->open(RouterOsTarget::fromDevice($device));
        try {
            return self::fromEthernetMonitor($conn->query('/interface/ethernet/print'), fn (array $names) => $conn->query(
                '/interface/ethernet/monitor',
                ['numbers' => implode(',', $names), 'once' => ''],
            ));
        } finally {
            $conn->close();
        }
    }

    /**
     * Pull optical readings out of an ethernet print + monitor: monitor every ethernet port by
     * name and keep the rows that carry an SFP power value. Copper ports and empty cages come
     * back without sfp-*-power, so there's no need to work out which ports are SFP first.
     *
     * @param  list<array<string, string>>  $ports
     * @param  callable(list<string>): list<array<string, string>>  $monitor
     * @return list<OpticalReading>
     */
    public static function fromEthernetMonitor(array $ports, callable $monitor): array
    {
        $names = array_values(array_filter(array_map(
            static fn (array $row): string => (string) ($row['name'] ?? ''),
            $ports,
        )));
        if ($names === []) {
            return [];
        }

        $out = [];
        foreach ($monitor($names) as $row) {
            $name = (string) ($row['name'] ?? '');
            $rx = OpticalReading::dbm($row['sfp-rx-power'] ?? null);
            $tx = OpticalReading::dbm($row['sfp-tx-power'] ?? null);
            if ($name === '' || ($rx === null && $tx === null)) {
                continue;
            }
            $out[] = new OpticalReading(ifIndex: null, name: $name, rxDbm: $rx, txDbm: $tx);
        }

        return $out;
    }
}
