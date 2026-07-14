<?php

namespace App\Actions\Import;

use App\Enums\DeviceType;
use App\Enums\PollMethod;
use App\Models\Credential;
use App\Models\Device;
use App\Models\DeviceMapPosition;
use App\Models\Map;
use App\Services\Import\LibreNms\LibreNmsSource;

/**
 * Create My Mate devices/credentials/maps from a LibreNMS source (MySQL or API). Idempotent:
 * devices upsert by mgmt_ip, maps by name. Credentials can come from LibreNMS SNMP communities
 * (MySQL), a single credential applied to all, or a per-device mapping (API). Returns a summary.
 */
class ImportFromLibreNms
{
    /** community value -> credential id, filled from existing creds + ones we create this run. */
    private array $communityToCredential = [];

    /**
     * @param  array{credential_id?:?int, credential_map?:array<string,int>, import_credentials?:bool, import_maps?:bool, only?:?list<string>, include_disabled?:bool}  $options
     * @return array<string, mixed>
     */
    public function __invoke(LibreNmsSource $source, array $options = []): array
    {
        $only = isset($options['only']) ? array_flip($options['only']) : null;
        $includeDisabled = (bool) ($options['include_disabled'] ?? false);
        $importCreds = (bool) ($options['import_credentials'] ?? false);
        $globalCred = $options['credential_id'] ?? null;
        $credMap = $options['credential_map'] ?? [];

        $summary = ['devices' => ['created' => 0, 'updated' => 0, 'skipped' => 0], 'credentials' => 0, 'maps' => 0, 'positions' => 0];

        if ($importCreds) {
            $this->primeCommunityCache();
        }

        /** @var array<string, int> $deviceIdByIp */
        $deviceIdByIp = [];

        foreach ($source->devices() as $d) {
            $ip = self::resolveIp($d['ip'] ?? null, $d['hostname'] ?? null);
            if ($ip === null || (! $includeDisabled && ($d['disabled'] ?? false)) || ($only !== null && ! isset($only[$ip]))) {
                $summary['devices']['skipped']++;

                continue;
            }

            $credentialId = $globalCred
                ?? ($credMap[$ip] ?? null)
                ?? ($importCreds ? $this->credentialForCommunity($d['snmp_community'] ?? null, $summary) : null);

            $exists = Device::where('mgmt_ip', $ip)->exists();
            $device = Device::updateOrCreate(
                ['mgmt_ip' => $ip],
                [
                    'name' => $d['sysname'] ?: ($d['hostname'] ?: $ip),
                    'poll_method' => $credentialId !== null ? PollMethod::Snmp : PollMethod::None,
                    'credential_id' => $credentialId,
                    'device_type' => DeviceType::Unknown,
                    'monitored' => true,
                    'vendor' => self::vendorFromOs($d['os'] ?? null),
                    'model' => $d['hardware'] ?: null,
                    'serial' => $d['serial'] ?: null,
                ],
            );

            $deviceIdByIp[$ip] = $device->id;
            $summary['devices'][$exists ? 'updated' : 'created']++;
        }

        if ($options['import_maps'] ?? false) {
            $this->importMaps($source, $deviceIdByIp, $summary);
        }

        return $summary;
    }

    private function importMaps(LibreNmsSource $source, array $deviceIdByIp, array &$summary): void
    {
        foreach ($source->maps() as $m) {
            $map = Map::firstOrCreate(['name' => $m['name']]);
            $summary['maps']++;

            foreach ($m['nodes'] as $node) {
                $ip = self::resolveIp($node['ip'] ?? null, $node['hostname'] ?? null);
                $deviceId = $ip !== null ? ($deviceIdByIp[$ip] ?? Device::where('mgmt_ip', $ip)->value('id')) : null;
                if ($deviceId === null) {
                    continue;
                }
                DeviceMapPosition::updateOrCreate(
                    ['map_id' => $map->id, 'device_id' => $deviceId],
                    ['x' => $node['x'], 'y' => $node['y']],
                );
                $summary['positions']++;
            }
        }
    }

    /** Load existing SNMP credentials into the community cache so we reuse rather than duplicate. */
    private function primeCommunityCache(): void
    {
        foreach (Credential::where('type', 'snmp')->get() as $cred) {
            $community = (string) $cred->snmp_community;
            if ($community !== '' && ! isset($this->communityToCredential[$community])) {
                $this->communityToCredential[$community] = $cred->id;
            }
        }
    }

    private function credentialForCommunity(?string $community, array &$summary): ?int
    {
        $community = trim((string) $community);
        if ($community === '') {
            return null;
        }
        if (isset($this->communityToCredential[$community])) {
            return $this->communityToCredential[$community];
        }

        $cred = Credential::create(['name' => "LibreNMS: {$community}", 'type' => 'snmp', 'snmp_community' => $community]);
        $this->communityToCredential[$community] = $cred->id;
        $summary['credentials']++;

        return $cred->id;
    }

    /** Prefer the resolved IP; fall back to the hostname only when it's itself an IP literal. */
    private static function resolveIp(?string $ip, ?string $hostname): ?string
    {
        $ip = trim((string) $ip);
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
            return $ip;
        }
        $hostname = trim((string) $hostname);

        return $hostname !== '' && filter_var($hostname, FILTER_VALIDATE_IP) !== false ? $hostname : null;
    }

    private static function vendorFromOs(?string $os): ?string
    {
        return match (strtolower(trim((string) $os))) {
            'routeros' => 'MikroTik',
            'edgeos', 'airos', 'unifi' => 'Ubiquiti',
            'ios', 'iosxe', 'nxos', 'iosxr' => 'Cisco',
            'junos' => 'Juniper',
            default => null,
        };
    }
}
