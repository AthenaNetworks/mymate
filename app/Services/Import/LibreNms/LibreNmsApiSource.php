<?php

namespace App\Services\Import\LibreNms;

use App\Support\OutboundHostGuard;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * LibreNMS via its REST API (X-Auth-Token). Devices only - the API doesn't cleanly expose SNMP
 * credentials or custom maps, so an API import maps each device to an existing My Mate
 * credential instead. SSRF-guarded (LibreNMS is typically on an internal address, allowed).
 */
class LibreNmsApiSource implements LibreNmsSource
{
    public function __construct(private string $baseUrl, private string $token) {}

    public function devices(): array
    {
        $url = rtrim($this->baseUrl, '/').'/api/v0/devices';
        if (! OutboundHostGuard::isSafeUrl($url)) {
            throw new RuntimeException('The LibreNMS URL is not a safe destination.');
        }

        $res = Http::withHeaders(['X-Auth-Token' => $this->token])->timeout(25)->acceptJson()->get($url);
        if (! $res->successful()) {
            throw new RuntimeException("LibreNMS API returned HTTP {$res->status()} (check the URL + token).");
        }

        return array_map(static fn (array $d): array => [
            'hostname' => (string) ($d['hostname'] ?? $d['sysName'] ?? ''),
            'ip' => isset($d['ip']) ? (string) $d['ip'] : null,
            'snmp_community' => isset($d['community']) ? (string) $d['community'] : null,
            'snmp_version' => isset($d['snmpver']) ? (string) $d['snmpver'] : null,
            'os' => isset($d['os']) ? (string) $d['os'] : null,
            'hardware' => isset($d['hardware']) ? (string) $d['hardware'] : null,
            'serial' => isset($d['serial']) ? (string) $d['serial'] : null,
            'sysname' => isset($d['sysName']) ? (string) $d['sysName'] : null,
            'disabled' => (bool) ($d['disabled'] ?? false),
        ], array_values((array) $res->json('devices', [])));
    }

    public function maps(): array
    {
        return []; // not available over the API
    }
}
