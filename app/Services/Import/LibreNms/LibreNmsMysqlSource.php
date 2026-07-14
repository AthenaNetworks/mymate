<?php

namespace App\Services\Import\LibreNms;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;

/**
 * LibreNMS via its MySQL/MariaDB database - the fuller import: SNMP credentials (community) and
 * custom maps come straight from the schema. Uses a throwaway runtime connection. Queries are
 * best-effort against the documented LibreNMS schema; a missing table (e.g. no custom maps on
 * an older version) is skipped rather than fatal.
 */
class LibreNmsMysqlSource implements LibreNmsSource
{
    private ?ConnectionInterface $conn = null;

    public function __construct(
        private string $host,
        private int $port,
        private string $database,
        private string $username,
        private string $password,
    ) {}

    private function connection(): ConnectionInterface
    {
        if ($this->conn !== null) {
            return $this->conn;
        }
        if (! in_array('mysql', PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException('The pdo_mysql PHP extension is not installed - needed to read a LibreNMS database. Install php-mysql (or use the API import).');
        }

        Config::set('database.connections._librenms', [
            'driver' => 'mysql',
            'host' => $this->host,
            'port' => $this->port,
            'database' => $this->database,
            'username' => $this->username,
            'password' => $this->password,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'options' => [PDO::ATTR_TIMEOUT => 8],
        ]);
        DB::purge('_librenms');

        return $this->conn = DB::connection('_librenms');
    }

    public function devices(): array
    {
        $rows = $this->connection()->table('devices')->get();

        return $rows->map(static fn ($r): array => [
            'hostname' => (string) ($r->hostname ?? ''),
            'ip' => isset($r->ip) && $r->ip !== '' ? (string) $r->ip : null,
            'snmp_community' => isset($r->community) && $r->community !== '' ? (string) $r->community : null,
            'snmp_version' => isset($r->snmpver) ? (string) $r->snmpver : null,
            'os' => isset($r->os) ? (string) $r->os : null,
            'hardware' => isset($r->hardware) ? (string) $r->hardware : null,
            'serial' => isset($r->serial) ? (string) $r->serial : null,
            'sysname' => isset($r->sysName) ? (string) $r->sysName : null,
            'disabled' => (bool) ($r->disabled ?? false),
        ])->all();
    }

    public function maps(): array
    {
        try {
            $maps = $this->connection()->table('custom_maps')->get(['custom_map_id', 'name']);
        } catch (\Throwable) {
            return []; // no custom maps on this version
        }

        $out = [];
        foreach ($maps as $map) {
            try {
                // Nodes reference a device (device_id) and carry x/y; join to devices for the ip.
                $nodes = $this->connection()->table('custom_map_nodes as n')
                    ->leftJoin('devices as d', 'd.device_id', '=', 'n.device_id')
                    ->where('n.custom_map_id', $map->custom_map_id)
                    ->whereNotNull('n.device_id')
                    ->get(['d.ip as ip', 'd.hostname as hostname', 'n.xpos as x', 'n.ypos as y', 'n.label as label']);
            } catch (\Throwable) {
                continue;
            }

            $out[] = [
                'name' => (string) $map->name,
                'nodes' => $nodes->map(static fn ($n): array => [
                    'ip' => isset($n->ip) && $n->ip !== '' ? (string) $n->ip : null,
                    'hostname' => isset($n->hostname) ? (string) $n->hostname : null,
                    'x' => (float) ($n->x ?? 0),
                    'y' => (float) ($n->y ?? 0),
                    'label' => isset($n->label) ? (string) $n->label : null,
                ])->all(),
            ];
        }

        return $out;
    }
}
