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

        $this->applyFacts($device, $facts);
    }

    /**
     * Apply a captured facts array to the device: never overwrite a hand-placed pin, only set the
     * device_type while it's still unknown (a manual override sticks), and skip an empty result.
     * Extracted so the central capture and the remote-agent ingestion path (#33) share it.
     *
     * @param  array<string, mixed>  $facts
     */
    public function applyFacts(Device $device, array $facts): void
    {
        $detectedType = $facts['device_type'] ?? null;
        unset($facts['device_type']);

        $facts = array_filter($facts, static fn ($v): bool => $v !== null && $v !== '');

        // Never let an SNMP-derived coordinate overwrite a pin the operator placed by hand.
        if ($device->geo_source === 'manual') {
            unset($facts['latitude'], $facts['longitude'], $facts['geo_source']);
        }

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

    /**
     * Build the facts array from raw SNMP values - the same derivation fromSnmp() uses, but taking
     * already-fetched values so the remote agent (#33) can walk the standard OIDs itself and hand
     * the raw results back for the server to parse (vendor/model knowledge stays here).
     *
     * @param  list<string>  $entModels   entPhysicalModelName values (row order)
     * @param  list<string>  $entSerials  entPhysicalSerialNum values (row order)
     * @return array<string, mixed>
     */
    public function factsFromRaw(string $sysDescr, ?int $uptimeTicks, ?int $memKb, string $location, array $entModels, array $entSerials): array
    {
        $model = self::cleanModel(self::firstMeaningful($entModels)) ?? self::modelFromSysDescr($sysDescr);
        $geo = self::parseLatLng($location);

        return [
            'vendor' => self::vendorFromSysDescr($sysDescr),
            'model' => $model,
            'serial' => self::firstMeaningful($entSerials),
            'ram_bytes' => ($memKb !== null && $memKb > 0) ? $memKb * 1024 : null,
            'uptime_seconds' => $uptimeTicks !== null ? intdiv($uptimeTicks, 100) : null,
            'device_type' => $this->snmpType($sysDescr)->value,
            'latitude' => $geo['lat'] ?? null,
            'longitude' => $geo['lng'] ?? null,
            'geo_source' => $geo !== null ? 'snmp' : null,
        ];
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

            // A RouterOS box has no SNMP to read, so pull the SNMP location it would advertise
            // straight from the API (best-effort); the parsing lives in factsFromRouterOsRaw.
            $location = '';
            try {
                $location = (string) (($conn->query('/snmp/print')[0] ?? [])['location'] ?? '');
            } catch (\Throwable) {
                // SNMP settings unreadable - just skip geo.
            }

            return $this->factsFromRouterOsRaw(
                (string) ($res['version'] ?? ''),
                $board['model'] ?? null,
                $board['board-name'] ?? null,
                $res['board-name'] ?? null,
                trim((string) ($board['serial-number'] ?? '')),
                trim((string) ($res['architecture-name'] ?? '')),
                trim((string) ($res['cpu'] ?? '')),
                (int) ($res['cpu-count'] ?? 0),
                (int) ($res['cpu-frequency'] ?? 0),
                (int) ($res['total-memory'] ?? 0),
                (string) ($res['uptime'] ?? ''),
                $location,
            );
        } finally {
            $conn->close();
        }
    }

    /**
     * Build the facts array from raw RouterOS-API values - the same derivation fromRouterOs() does,
     * so the remote agent (#33) can read them itself and hand them back for the server to parse.
     *
     * @return array<string, mixed>
     */
    public function factsFromRouterOsRaw(
        string $version,
        ?string $boardModel,
        ?string $boardName,
        ?string $resBoardName,
        string $serial,
        string $arch,
        string $cpu,
        int $cpuCount,
        int $cpuFreq,
        int $totalMemory,
        string $uptime,
        string $location,
    ): array {
        $model = $boardModel ?: ($boardName ?: ($resBoardName ?: null));
        $geo = self::parseLatLng($location);

        return [
            'vendor' => 'MikroTik',
            'model' => self::cleanModel($model),
            'arch' => $arch !== '' ? $arch : null,
            'serial' => $serial !== '' ? $serial : null,
            'cpu' => self::formatCpu($cpu, $cpuCount, $cpuFreq),
            'ram_bytes' => $totalMemory > 0 ? $totalMemory : null,
            'uptime_seconds' => $uptime !== '' ? self::parseRouterOsUptime($uptime) : null,
            'os_version' => UpgradeDevice::normalizeVersion($version),
            'device_type' => $this->routerOsType((string) ($model ?? ''))->value,
            'latitude' => $geo['lat'] ?? null,
            'longitude' => $geo['lng'] ?? null,
            'geo_source' => $geo !== null ? 'snmp' : null,
        ];
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
        $res = $this->snmp->get($device->mgmt_ip, $community, [$oids['sys_descr'], $oids['sys_uptime'], $oids['hr_memory'], $oids['sys_location']]);

        $descr = (string) ($res[$oids['sys_descr']] ?? '');
        $ticks = $res[$oids['sys_uptime']] ?? null;
        $memKb = $res[$oids['hr_memory']] ?? null; // hrMemorySize is physical RAM in KB
        $location = (string) ($res[$oids['sys_location']] ?? '');

        // ENTITY-MIB gives a clean cross-vendor model + serial (chassis row); the raw walk values
        // go to factsFromRaw, which does the vendor derivation - reused by the agent path too (#33).
        $entModels = $this->snmp->walk($device->mgmt_ip, $community, $oids['ent_model']);
        $entSerials = $this->snmp->walk($device->mgmt_ip, $community, $oids['ent_serial']);

        return $this->factsFromRaw(
            $descr,
            $ticks !== null ? (int) $ticks : null,
            is_numeric($memKb) ? (int) $memKb : null,
            $location,
            array_values($entModels),
            array_values($entSerials),
        );
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

    /**
     * Pull coordinates out of an SNMP location string when it carries them in "[lat, lng]"
     * form (also accepts a bare "lat,lng"/"lat lng" of decimals). Strict enough not to mistake
     * a "Rack 5, Room 12" style label for coordinates, and both values must be in range.
     *
     * @return array{lat: float, lng: float}|null
     */
    private static function parseLatLng(string $location): ?array
    {
        $location = trim($location);
        if ($location === '') {
            return null;
        }

        // Bracketed "[lat, lng]" (ints or decimals), else a plain pair that both have a decimal
        // point (so a plain-text label with whole numbers isn't misread as a coordinate).
        if (preg_match('/\[\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*\]/', $location, $m) !== 1
            && preg_match('/(-?\d+\.\d+)\s*[,\s]\s*(-?\d+\.\d+)/', $location, $m) !== 1) {
            return null;
        }

        $lat = (float) $m[1];
        $lng = (float) $m[2];
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return null; // out of range - not a coordinate
        }

        return ['lat' => $lat, 'lng' => $lng];
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
