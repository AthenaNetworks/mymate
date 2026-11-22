<?php

namespace App\Actions\Links;

use App\Enums\PollMethod;
use App\Models\Device;
use App\Services\Neighbors\NeighborTables;
use App\Services\RouterOs\RouterOsClient;
use App\Services\RouterOs\RouterOsTarget;
use App\Services\Snmp\SnmpClient;
use App\Services\Snmp\SnmpCredential;
use Throwable;

/**
 * Read a centrally polled device's neighbour tables (GitHub #85): LLDP-MIB and the CDP cache over
 * SNMP, or /ip/neighbor over the RouterOS API. Best-effort like the other discovery reads: a
 * device without LLDP, or one that times out, gives empty tables, never an exception. Agent-polled
 * devices are read by their agent instead (DiscoverInterfacesJob hands them over).
 */
class ReadNeighbors
{
    public function __construct(
        private SnmpClient $snmp,
        private RouterOsClient $routerOs,
    ) {}

    public function __invoke(Device $device): NeighborTables
    {
        $device->loadMissing('credential');
        if ($device->mgmt_ip === null || $device->credential === null) {
            return new NeighborTables;
        }

        try {
            return match ($device->poll_method) {
                PollMethod::Snmp => $this->fromSnmp($device),
                PollMethod::RouterOs => $this->fromRouterOs($device),
                PollMethod::None => new NeighborTables,
            };
        } catch (Throwable) {
            return new NeighborTables;
        }
    }

    private function fromSnmp(Device $device): NeighborTables
    {
        $cred = SnmpCredential::fromCredential($device->credential);
        $walks = [];
        // Each table's key column first: no LLDP or CDP neighbours means the rest of that table
        // isn't worth asking for, which keeps this cheap on the many devices without either. A
        // walk that throws is a transport failure (the device went quiet), so stop there.
        $tables = [
            [NeighborTables::LLDP_REM_CHASSIS_ID, NeighborTables::LLDP_REM_SYS_NAME, NeighborTables::LLDP_REM_PORT_ID,
                NeighborTables::LLDP_REM_PORT_ID_SUBTYPE, NeighborTables::LLDP_REM_PORT_DESC, NeighborTables::LLDP_REM_MAN_ADDR,
                NeighborTables::LLDP_LOC_PORT_ID, NeighborTables::LLDP_LOC_PORT_DESC],
            [NeighborTables::CDP_DEVICE_ID, NeighborTables::CDP_DEVICE_PORT, NeighborTables::CDP_ADDRESS_TYPE, NeighborTables::CDP_ADDRESS],
        ];
        try {
            foreach ($tables as $columns) {
                foreach ($columns as $i => $oid) {
                    $rows = $this->snmp->walk($device->mgmt_ip, $cred, $oid);
                    if ($rows === [] && $i === 0) {
                        break;
                    }
                    if ($rows !== []) {
                        $walks[$oid] = array_map('strval', $rows);
                    }
                }
            }
        } catch (Throwable) {
            return new NeighborTables($walks);
        }
        try {
            $scalars = $this->snmp->get($device->mgmt_ip, $cred, NeighborTables::SCALARS);
        } catch (Throwable) {
            $scalars = [];
        }

        return new NeighborTables($walks, array_map('strval', $scalars));
    }

    private function fromRouterOs(Device $device): NeighborTables
    {
        $c = $device->credential;
        if ($c->type !== PollMethod::RouterOs->value || ! $c->username) {
            return new NeighborTables;
        }
        $port = $c->api_port ?: 8728;
        $conn = $this->routerOs->open(new RouterOsTarget(
            host: $device->mgmt_ip,
            port: $port,
            username: (string) $c->username,
            password: (string) $c->password,
            timeout: max(1, (int) config('mymate.routeros.timeout', 3)),
            ssl: $port === 8729,
        ));
        try {
            $rows = $conn->query('/ip/neighbor/print');
        } finally {
            $conn->close();
        }

        return new NeighborTables(routeros: array_values(array_filter($rows, 'is_array')));
    }
}
