<?php

namespace App\Actions\Devices;

use App\Enums\DeviceType;
use App\Enums\PollMethod;
use App\Models\Device;
use App\Services\RouterOs\RouterOsClient;
use App\Services\RouterOs\RouterOsTarget;
use App\Services\Snmp\SnmpClient;
use App\Support\EngineLog;
use Throwable;

/**
 * Best-effort capture of device "facts" - vendor / model / uptime / type - from the
 * live device, run on the slow discovery cadence (DiscoverInterfacesJob). Never
 * throws: a filtered/black-holing host must not break a discovery tick. A detected
 * device_type is only written while the device is still `unknown`, so a manual
 * override sticks. Secrets are never logged.
 */
class CaptureDeviceFacts
{
    public function __construct(
        private SnmpClient $snmp,
        private RouterOsClient $routerOs,
    ) {}

    public function __invoke(Device $device): void
    {
        try {
            $facts = match ($device->poll_method) {
                PollMethod::RouterOs => $this->fromRouterOs($device),
                PollMethod::Snmp => $this->fromSnmp($device),
                // Ping-only devices expose no facts source - nothing to
                // capture. (They're already excluded from the discovery cadence that
                // calls this; this arm just keeps the match total.)
                PollMethod::None => [],
            };
        } catch (Throwable $e) {
            EngineLog::debug('facts: capture skipped', [
                'device_id' => $device->id,
                'device' => $device->name,
                'error' => $e->getMessage(), // host/error only - never credentials
            ]);

            return;
        }

        $detectedType = $facts['device_type'] ?? null;
        unset($facts['device_type']);

        $facts = array_filter($facts, static fn ($v): bool => $v !== null && $v !== '');

        // Only set the type when we detected a real one and the device is still unknown.
        if ($detectedType !== null
            && $detectedType !== DeviceType::Unknown->value
            && ($device->device_type ?? DeviceType::Unknown) === DeviceType::Unknown) {
            $facts['device_type'] = $detectedType;
        }

        if ($facts === []) {
            return;
        }
        if (isset($facts['uptime_seconds'])) {
            $facts['uptime_at'] = now();
        }

        $device->update($facts);
    }

    /** @return array<string, mixed> */
    private function fromRouterOs(Device $device): array
    {
        $device->loadMissing('credential');
        $c = $device->credential;
        if ($c === null || $c->type !== PollMethod::RouterOs->value || ! $c->username) {
            return [];
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
            $res = $conn->query('/system/resource')[0] ?? [];
            $board = $conn->query('/system/routerboard')[0] ?? [];
            $model = $board['model'] ?? $board['board-name'] ?? $res['board-name'] ?? null;
            $uptime = isset($res['uptime']) ? self::parseRouterOsUptime((string) $res['uptime']) : null;

            return [
                'vendor' => 'MikroTik',
                'model' => $model !== null ? trim((string) $model) : null,
                'uptime_seconds' => $uptime,
                'os_version' => UpgradeDevice::normalizeVersion((string) ($res['version'] ?? '')),
                'device_type' => $this->routerOsType((string) ($model ?? ''))->value,
            ];
        } finally {
            $conn->close();
        }
    }

    /** @return array<string, mixed> */
    private function fromSnmp(Device $device): array
    {
        $device->loadMissing('credential');
        $community = $device->credential?->snmp_community;
        if ($community === null || $community === '') {
            return [];
        }

        /** @var array<string, string> $oids */
        $oids = config('mymate.snmp.oids', []);
        $res = $this->snmp->get($device->mgmt_ip, $community, [$oids['sys_descr'], $oids['sys_uptime']]);

        $descr = (string) ($res[$oids['sys_descr']] ?? '');
        $ticks = $res[$oids['sys_uptime']] ?? null;

        return [
            'vendor' => self::vendorFromSysDescr($descr),
            // sysDescr model parsing is unreliable across vendors - leave for manual entry.
            'model' => null,
            'uptime_seconds' => $ticks !== null ? intdiv((int) $ticks, 100) : null, // TimeTicks (1/100s) -> s
            'device_type' => $this->snmpType($descr)->value,
        ];
    }

    /** "1w2d3h4m5s" -> seconds; null if nothing parses. */
    public static function parseRouterOsUptime(string $uptime): ?int
    {
        if (! preg_match_all('/(\d+)([wdhms])/', strtolower(trim($uptime)), $m, PREG_SET_ORDER)) {
            return null;
        }

        $mult = ['w' => 604800, 'd' => 86400, 'h' => 3600, 'm' => 60, 's' => 1];
        $total = 0;
        foreach ($m as $part) {
            $total += (int) $part[1] * ($mult[$part[2]] ?? 0);
        }

        return $total;
    }

    /** Best-effort vendor from an SNMP sysDescr; null when empty. */
    public static function vendorFromSysDescr(string $descr): ?string
    {
        $d = strtolower($descr);
        if ($d === '') {
            return null;
        }

        $map = [
            'mikrotik' => 'MikroTik', 'routeros' => 'MikroTik',
            'cisco' => 'Cisco', 'juniper' => 'Juniper', 'arista' => 'Arista',
            'ubiquiti' => 'Ubiquiti', 'edgeos' => 'Ubiquiti', 'unifi' => 'Ubiquiti',
            'huawei' => 'Huawei', 'fortinet' => 'Fortinet', 'aruba' => 'Aruba',
            'windows' => 'Microsoft', 'linux' => 'Linux',
        ];
        foreach ($map as $needle => $vendor) {
            if (str_contains($d, $needle)) {
                return $vendor;
            }
        }

        $first = preg_split('/\s+/', trim($descr))[0] ?? '';

        return $first === '' ? null : mb_substr($first, 0, 64);
    }

    private function routerOsType(string $model): DeviceType
    {
        $m = strtolower($model);

        return match (true) {
            str_contains($m, 'crs'), str_contains($m, 'css') => DeviceType::Switch,
            str_contains($m, 'cap'), str_contains($m, 'hap'), str_contains($m, 'wap'),
            str_contains($m, 'sxt'), str_contains($m, 'lhg'), str_contains($m, 'mant'),
            str_contains($m, 'audience') => DeviceType::Ap,
            default => DeviceType::Router, // RouterOS gear is a router unless it looks otherwise
        };
    }

    private function snmpType(string $descr): DeviceType
    {
        $d = strtolower($descr);

        return match (true) {
            $d === '' => DeviceType::Unknown,
            str_contains($d, 'switch') => DeviceType::Switch,
            str_contains($d, 'access point'), str_contains($d, 'wireless'), str_contains($d, 'wi-fi') => DeviceType::Ap,
            str_contains($d, 'router'), str_contains($d, 'routeros') => DeviceType::Router,
            str_contains($d, 'windows'), str_contains($d, 'server'), str_contains($d, 'linux') => DeviceType::Server,
            default => DeviceType::Unknown,
        };
    }
}
