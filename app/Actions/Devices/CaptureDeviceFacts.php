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
            // The API needs the /print action (a bare "/system/resource" traps).
            $res = $conn->query('/system/resource/print')[0] ?? [];
            $board = $conn->query('/system/routerboard/print')[0] ?? [];
            $model = $board['model'] ?? $board['board-name'] ?? $res['board-name'] ?? null;
            $uptime = isset($res['uptime']) ? self::parseRouterOsUptime((string) $res['uptime']) : null;

            $ram = (int) ($res['total-memory'] ?? 0);
            $serial = trim((string) ($board['serial-number'] ?? ''));

            $arch = trim((string) ($res['architecture-name'] ?? ''));

            return [
                'vendor' => 'MikroTik',
                'model' => self::cleanModel($model),
                'arch' => $arch !== '' ? $arch : null,
                'serial' => $serial !== '' ? $serial : null,
                'cpu' => self::formatCpu(
                    trim((string) ($res['cpu'] ?? '')),
                    (int) ($res['cpu-count'] ?? 0),
                    (int) ($res['cpu-frequency'] ?? 0),
                ),
                'ram_bytes' => $ram > 0 ? $ram : null,
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
        $community = \App\Services\Snmp\SnmpCredential::fromCredential($device->credential);
        if (! $community->isUsable()) {
            return [];
        }

        /** @var array<string, string> $oids */
        $oids = config('mymate.snmp.oids', []);
        $res = $this->snmp->get($device->mgmt_ip, $community, [$oids['sys_descr'], $oids['sys_uptime'], $oids['hr_memory']]);

        $descr = (string) ($res[$oids['sys_descr']] ?? '');
        $ticks = $res[$oids['sys_uptime']] ?? null;

        // ENTITY-MIB gives a clean cross-vendor model + serial (chassis row). Walk each and
        // take the first meaningful value. Falls back to null (never a wrong guess).
        $model = self::cleanModel(self::firstMeaningful($this->snmp->walk($device->mgmt_ip, $community, $oids['ent_model'])));
        // MikroTik's ENTITY-MIB model row is a useless hex board id, but the real board name is
        // right there in sysDescr ("RouterOS RB5009UPr+S+") - use it when ENTITY gave nothing.
        if ($model === null) {
            $model = self::modelFromSysDescr($descr);
        }
        $serial = self::firstMeaningful($this->snmp->walk($device->mgmt_ip, $community, $oids['ent_serial']));

        // hrMemorySize is physical RAM in KB.
        $memKb = $res[$oids['hr_memory']] ?? null;

        return [
            'vendor' => self::vendorFromSysDescr($descr),
            'model' => $model,
            'serial' => $serial,
            'ram_bytes' => is_numeric($memKb) && (int) $memKb > 0 ? (int) $memKb * 1024 : null,
            'uptime_seconds' => $ticks !== null ? intdiv((int) $ticks, 100) : null, // TimeTicks (1/100s) -> s
            'device_type' => $this->snmpType($descr)->value,
        ];
    }

    /** First non-empty, non-placeholder value from an SNMP walk (skips "", "N/A", "none"). */
    /**
     * A model should read like a product name. Some MikroTik SNMP boards expose a raw board
     * id ("0x0002") or a bare number in the ENTITY-MIB model row instead of a real name - those
     * can't map to a product page or mean anything to an operator, so drop them to null and let
     * the drawn family icon stand in. (Serials, which are legitimately numeric, are untouched.)
     */
    /**
     * Best-effort model from sysDescr when the ENTITY-MIB model row is missing or junk. MikroTik
     * puts the board name in sysDescr ("RouterOS RB5009UPr+S+") and in the longer entPhysicalDescr
     * form ("RouterOS 7.x (...) on RB5009UPr+S+") - both are the real, operator-recognisable model.
     */
    private static function modelFromSysDescr(string $descr): ?string
    {
        $descr = trim($descr);
        // entPhysicalDescr / longer form: "RouterOS 7.x (...) on RB5009UPr+S+".
        if (preg_match('/\bon\s+(\S+)\s*$/i', $descr, $m) === 1) {
            return self::cleanModel($m[1]);
        }
        // sysDescr: "RouterOS <MODEL> [<version> (channel)]" - the model is the token right
        // after RouterOS. Older builds omit the version ("RouterOS RB5009UPr+S+"); newer ones
        // append it ("RouterOS RB5009UPr+S+ 7.23.2 (stable)"), so match without anchoring to
        // end-of-string. cleanModel() drops a bare version token (a CHR-less build reporting
        // just "RouterOS 7.23.2").
        if (preg_match('/^RouterOS\s+(\S+)/i', $descr, $m) === 1) {
            return self::cleanModel($m[1]);
        }

        return null;
    }

    private static function cleanModel(mixed $model): ?string
    {
        $m = trim((string) $model);
        // Drop non-names: hex board ids ("0x0002"), and bare or dotted version numbers
        // ("123", "7.23.2") that a modelless sysDescr can leave in the model slot.
        if ($m === '' || preg_match('/^0x[0-9a-f]+$/i', $m) === 1 || preg_match('/^\d+(\.\d+)*$/', $m) === 1) {
            return null;
        }

        return mb_substr($m, 0, 128);
    }

    private static function firstMeaningful(array $walk): ?string
    {
        foreach ($walk as $value) {
            $v = trim((string) $value);
            if ($v !== '' && ! in_array(strtolower($v), ['n/a', 'none', 'unknown', '0'], true)) {
                return mb_substr($v, 0, 128);
            }
        }

        return null;
    }

    /** "ARM", 4, 880 -> "ARM 4-core @ 880 MHz". Any piece may be absent; null when all are. */
    private static function formatCpu(string $name, int $count, int $freqMhz): ?string
    {
        $s = $name;
        if ($count > 1) {
            $s = trim("{$s} {$count}-core");
        }
        if ($freqMhz > 0) {
            $freq = $freqMhz >= 1000 ? round($freqMhz / 1000, 1).' GHz' : "{$freqMhz} MHz";
            $s = trim($s === '' ? $freq : "{$s} @ {$freq}");
        }

        return $s === '' ? null : $s;
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
            'ubiquiti' => 'Ubiquiti', 'edgeos' => 'Ubiquiti', 'unifi' => 'Ubiquiti', 'airos' => 'Ubiquiti', 'airmax' => 'Ubiquiti',
            'cambium' => 'Cambium', 'epmp' => 'Cambium', 'canopy' => 'Cambium', 'cnpilot' => 'Cambium', 'pmp 450' => 'Cambium',
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
