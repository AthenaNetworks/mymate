<?php

namespace App\Actions\Import;

use App\Enums\DeviceType;
use App\Enums\ImportMode;
use App\Enums\PollMethod;
use App\Models\Credential;
use App\Models\Device;
use App\Models\ImportRun;
use App\Models\Map;
use App\Models\NetworkInterface;
use App\Support\CsvReader;
use App\Support\EngineLog;
use App\Support\ImportProgress;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Convert the CSVs produced by {@see ExtractDudeDatabase} into My Mate rows.
 *
 * Mode (FR-Dude): UPSERT keeps existing config and updates-or-inserts on natural
 * keys (devices by mgmt_ip, interfaces by (device,if_index), maps/credentials by
 * name); FRESH wipes the import-managed domain first. Either way it never touches
 * users, settings, or alert config.
 *
 * Config (`credentials`, `devices`, `interfaces`, `maps`, positions, `links`) imports
 * inside one transaction so a failure rolls back cleanly. History (millions of
 * chart_values rows -> interface_samples) imports after, outside the transaction and
 * best-effort, so a hiccup there can't undo the config import.
 */
class ImportDudeDatabase
{
    private string $dir;

    /** dudeDeviceID -> our device id. */
    private array $deviceIdMap = [];

    /** "dudeDeviceID:if_index" -> our interface id. */
    private array $ifaceIdMap = [];

    /** dude mapID -> our map id. */
    private array $mapIdMap = [];

    /** our interface id -> speed_mbps (for util computation during history import). */
    private array $ifaceSpeed = [];

    /** running counts, written to import_runs.summary. */
    private array $summary = [];

    private ImportProgress $progress;

    public function __construct(private Settings $settings) {}

    public function __invoke(ImportRun $run, string $exportDir, ?ImportProgress $progress = null): array
    {
        $this->dir = rtrim($exportDir, '/');
        $this->summary = [];
        $this->progress = $progress ?? new ImportProgress($run);

        // Config (small) imports in one transaction - a cancel or error here rolls
        // everything back, so the DB is never left half-imported.
        DB::transaction(function () use ($run): void {
            $this->progress->checkCancelled();
            if ($run->mode === ImportMode::Fresh) {
                $this->progress->stage('Clearing existing data', 1);
                $this->wipe();
            }
            $this->progress->stage('Importing credentials', 3);
            $this->importCredentials();
            $this->progress->checkCancelled();
            $this->progress->stage('Importing devices', 5);
            $this->importDevices();
            $this->resolveParents();
            $this->progress->checkCancelled();
            $this->progress->stage('Importing interfaces', 8);
            $this->importInterfaces();
            $this->progress->checkCancelled();
            $this->progress->stage('Importing maps', 10);
            $this->importMaps();
            $this->importPlacements();
            $this->progress->checkCancelled();
            $this->progress->stage('Importing links', 12);
            $this->importLinks();
            $this->progress->stage('Importing outages', 14);
            $this->importOutages();
        });

        if ($run->include_history) {
            $this->progress->stage('Importing history', 15);
            $this->importHistory();
        }

        $this->progress->stage('Finalising', 100);

        return $this->summary;
    }

    // --- Fresh mode -------------------------------------------------------

    /**
     * Wipe the import-managed domain. Deleting devices cascades to interfaces, links,
     * device_map_positions and outages (FKs). interface_samples has no FK, so truncate
     * its partitions explicitly. Auth/settings/alerts are intentionally left alone.
     */
    private function wipe(): void
    {
        DB::table('links')->delete();
        DB::table('devices')->delete();         // cascades interfaces, positions, outages
        DB::table('credentials')->delete();
        Map::query()->delete();
        DB::statement('TRUNCATE TABLE interface_samples');
        $this->summary['wiped'] = true;
    }

    // --- Credentials ------------------------------------------------------

    /** profile name -> credential id (snmp); "user|pass" -> credential id (routeros). */
    private array $snmpCredByName = [];

    private array $rosCredByLogin = [];

    private function importCredentials(): void
    {
        $created = 0;
        $updated = 0;

        // One SNMP credential per distinct Dude SNMP profile (skip disabled).
        if (CsvReader::exists($this->path('snmp_profiles.csv'))) {
            foreach (CsvReader::rows($this->path('snmp_profiles.csv')) as $r) {
                if (($r['version'] ?? '') === 'disabled' || ($r['community'] ?? '') === '') {
                    continue;
                }
                $name = trim($r['name'] ?? '') ?: 'Dude SNMP '.$r['profileID'];
                [$cred, $isNew] = $this->upsertCredential($name, [
                    'type' => 'snmp',
                    'snmp_community' => $r['community'],
                ]);
                $this->snmpCredByName[$name] = $cred->id;
                $isNew ? $created++ : $updated++;
            }
        }

        // One RouterOS credential per distinct (username, password) from devices.
        if (CsvReader::exists($this->path('credentials.csv'))) {
            foreach (CsvReader::rows($this->path('credentials.csv')) as $r) {
                $user = trim($r['username'] ?? '');
                $pass = $r['password'] ?? '';
                if ($user === '') {
                    continue;
                }
                $login = $user.'|'.$pass;
                if (isset($this->rosCredByLogin[$login])) {
                    continue;
                }
                $name = 'RouterOS: '.$user;
                [$cred, $isNew] = $this->upsertCredential($name, [
                    'type' => 'routeros',
                    'username' => $user,
                    'password' => $pass,
                ]);
                $this->rosCredByLogin[$login] = $cred->id;
                $isNew ? $created++ : $updated++;
            }
        }

        $this->summary['credentials'] = ['created' => $created, 'updated' => $updated];
    }

    /** @return array{0: Credential, 1: bool} [model, wasCreated] */
    private function upsertCredential(string $name, array $attrs): array
    {
        $existing = Credential::where('name', $name)->first();
        if ($existing !== null) {
            $existing->fill($attrs)->save();

            return [$existing, false];
        }

        return [Credential::create(['name' => $name] + $attrs), true];
    }

    // --- Devices ----------------------------------------------------------

    /** dude device name -> our device id (for parent resolution). */
    private array $deviceNameMap = [];

    /** dudeDeviceID -> dude parent name (deferred until all devices exist). */
    private array $pendingParent = [];

    private function importDevices(): void
    {
        // Join credentials.csv (login + effective SNMP) onto devices by deviceID.
        $cred = [];
        if (CsvReader::exists($this->path('credentials.csv'))) {
            foreach (CsvReader::rows($this->path('credentials.csv')) as $r) {
                $cred[$r['deviceID']] = $r;
            }
        }

        $created = 0;
        $updated = 0;
        foreach (CsvReader::rows($this->path('devices.csv')) as $r) {
            $dudeId = $r['deviceID'];
            $ip = trim($r['ip'] ?? '');
            $name = trim($r['name'] ?? '') ?: ($ip ?: 'device-'.$dudeId);
            if ($ip === '') {
                continue; // a device with no management IP can't be polled - skip
            }

            $c = $cred[$dudeId] ?? [];
            [$pollMethod, $credentialId] = $this->resolveDeviceCredential($c);

            $attrs = [
                'name' => $name,
                'poll_method' => $pollMethod->value,
                'credential_id' => $credentialId,
                'device_type' => $this->mapDeviceType($r['device_type'] ?? '')->value,
            ];

            // Upsert by mgmt_ip (the operational identity). Preserve live/operator
            // fields on an existing device - only set identity/config from the import.
            $device = Device::where('mgmt_ip', $ip)->first();
            if ($device !== null) {
                $device->fill($attrs)->save();
                $updated++;
            } else {
                $device = Device::create($attrs + ['mgmt_ip' => $ip]);
                $created++;
            }

            $this->deviceIdMap[$dudeId] = $device->id;
            $this->deviceNameMap[$name] = $device->id;
            $parent = trim($r['parent_device'] ?? '');
            if ($parent !== '') {
                $this->pendingParent[$dudeId] = $parent;
            }
        }

        $this->summary['devices'] = ['created' => $created, 'updated' => $updated];
    }

    /** @return array{0: PollMethod, 1: ?int} */
    private function resolveDeviceCredential(array $c): array
    {
        $snmpVer = $c['snmp_version'] ?? '';
        $community = $c['snmp_community'] ?? '';
        $user = trim($c['username'] ?? '');

        // SNMP if the device has a working SNMP profile (Dude's monitoring path);
        // otherwise fall back to the RouterOS API login when present.
        if ($community !== '' && $snmpVer !== 'disabled' && $snmpVer !== '') {
            $profileName = trim($c['snmp_profile'] ?? '');
            $credId = $this->snmpCredByName[$profileName] ?? null;

            return [PollMethod::Snmp, $credId];
        }

        if ($user !== '') {
            $credId = $this->rosCredByLogin[$user.'|'.($c['password'] ?? '')] ?? null;

            return [PollMethod::RouterOs, $credId];
        }

        return [PollMethod::Snmp, null];
    }

    private function mapDeviceType(string $label): DeviceType
    {
        $l = strtolower($label);

        return match (true) {
            str_contains($l, 'switch') || str_contains($l, 'bridge') => DeviceType::Switch,
            str_contains($l, 'wireless') || str_contains($l, 'ap') || str_contains($l, 'access point') => DeviceType::Ap,
            str_contains($l, 'router') => DeviceType::Router,
            str_contains($l, 'internet') || str_contains($l, 'cloud') || str_contains($l, 'gateway') => DeviceType::Internet,
            str_contains($l, 'server') || str_contains($l, 'computer') || str_contains($l, 'windows')
                || str_contains($l, 'camera') || str_contains($l, 'nas') || str_contains($l, 'printer') => DeviceType::Server,
            default => DeviceType::Unknown,
        };
    }

    /** Second pass: wire parent_device_id now that every device id is known. */
    private function resolveParents(): void
    {
        foreach ($this->pendingParent as $dudeId => $parentName) {
            $childId = $this->deviceIdMap[$dudeId] ?? null;
            $parentId = $this->deviceNameMap[$parentName] ?? null;
            if ($childId !== null && $parentId !== null && $childId !== $parentId) {
                Device::where('id', $childId)->update(['parent_device_id' => $parentId]);
            }
        }
    }

    // --- Interfaces -------------------------------------------------------

    private function importInterfaces(): void
    {
        if (! CsvReader::exists($this->path('interfaces.csv'))) {
            $this->summary['interfaces'] = ['created' => 0, 'updated' => 0];

            return;
        }

        $created = 0;
        $updated = 0;
        foreach (CsvReader::rows($this->path('interfaces.csv')) as $r) {
            $deviceId = $this->deviceIdMap[$r['deviceID']] ?? null;
            if ($deviceId === null || ! is_numeric($r['if_index'])) {
                continue;
            }
            $ifIndex = (int) $r['if_index'];
            $speedMbps = $this->bpsToMbps($r['speed_bps'] ?? '');

            $iface = NetworkInterface::where('device_id', $deviceId)->where('if_index', $ifIndex)->first();
            if ($iface !== null) {
                // Interface speed is read-only from SNMP - refresh it
                // from the imported value when present. (Capacity overrides live on links.)
                $patch = ['name' => $r['name'] ?: $iface->name];
                if ($speedMbps !== null) {
                    $patch['speed_mbps'] = $speedMbps;
                }
                $iface->fill($patch)->save();
                $updated++;
            } else {
                $iface = NetworkInterface::create([
                    'device_id' => $deviceId,
                    'if_index' => $ifIndex,
                    'name' => $r['name'] ?: ('if'.$ifIndex),
                    'speed_mbps' => $speedMbps,
                ]);
                $created++;
            }

            $this->ifaceIdMap[$r['deviceID'].':'.$ifIndex] = $iface->id;
            $this->ifaceSpeed[$iface->id] = $speedMbps;
        }

        $this->summary['interfaces'] = ['created' => $created, 'updated' => $updated];
    }

    // --- Maps + placements ------------------------------------------------

    private function importMaps(): void
    {
        if (! CsvReader::exists($this->path('network_maps.csv'))) {
            return;
        }

        $created = 0;
        $first = true;
        foreach (CsvReader::rows($this->path('network_maps.csv')) as $r) {
            $name = trim($r['name'] ?? '') ?: ('Map '.$r['mapID']);
            $map = Map::firstOrCreate(['name' => $name], ['is_default' => false]);
            if ($map->wasRecentlyCreated) {
                $created++;
            }
            $this->mapIdMap[$r['mapID']] = $map->id;
            $first = false;
        }
        $this->summary['maps'] = ['created' => $created];
    }

    private function importPlacements(): void
    {
        if (! CsvReader::exists($this->path('placements.csv'))) {
            return;
        }

        // Buffer placements per map so coordinates can be normalised as a set
        // (Dude packs tiny icons; My Mate's larger cards need decompacting).
        $byMap = [];   // ourMapId => [ ourDeviceId => [x, y] ]
        foreach (CsvReader::rows($this->path('placements.csv')) as $r) {
            $deviceId = $this->deviceIdMap[$r['deviceID']] ?? null;
            $mapId = $this->mapIdMap[$r['mapID']] ?? null;
            if ($deviceId === null || $mapId === null) {
                continue;
            }
            $byMap[$mapId][$deviceId] = [(float) ($r['x'] ?: 0), (float) ($r['y'] ?: 0)];
        }

        $count = 0;
        $deviceMainPos = [];
        foreach ($byMap as $mapId => $points) {
            $placed = $this->normalisePlacements($points); // scale + declump -> clean spacing
            foreach ($placed as $deviceId => [$x, $y]) {
                DB::table('device_map_positions')->updateOrInsert(
                    ['device_id' => $deviceId, 'map_id' => $mapId],
                    ['x' => $x, 'y' => $y, 'updated_at' => now(), 'created_at' => now()],
                );
                // Mirror the first map's position onto devices.map_x/y (legacy single-map field).
                if (! isset($deviceMainPos[$deviceId])) {
                    $deviceMainPos[$deviceId] = [$x, $y];
                }
                $count++;
            }
        }
        foreach ($deviceMainPos as $deviceId => [$x, $y]) {
            Device::where('id', $deviceId)->update(['map_x' => $x, 'map_y' => $y]);
        }
        $this->summary['placements'] = $count;
    }

    /**
     * Compensate for the node-size mismatch: Dude lays out tiny icons (~55-130px
     * apart) but a My Mate card is ~200x84px, so raw coords overlap badly. Two steps,
     * both shape-preserving:
     *   1. Uniform scale off the map's nearest-neighbour distance toward `min_spacing`
     *      (clamped so one tight pair can't blow the map up), re-origined to a padded
     *      top-left.
     *   2. A light AABB declump that pushes any still-overlapping pair apart along its
     *      least-penetration axis - clears residual/coincident collisions so the import
     *      lands clean.
     *
     * @param  array<int, array{0: float, 1: float}>  $points  deviceId => [x, y]
     * @return array<int, array{0: float, 1: float}> deviceId => [x, y] (normalised)
     */
    private function normalisePlacements(array $points): array
    {
        $cfg = (array) config('mymate.import.layout', []);
        $minSpacing = (float) ($cfg['min_spacing'] ?? 240);
        $percentile = (float) ($cfg['scale_percentile'] ?? 0.5);
        $maxScale = (float) ($cfg['max_scale'] ?? 4.0);
        $cellW = (float) ($cfg['node_w'] ?? 240);
        $cellH = (float) ($cfg['node_h'] ?? 120);
        $pad = (float) ($cfg['pad'] ?? 80);
        $iterations = (int) ($cfg['declump_iterations'] ?? 80);

        $ids = array_keys($points);
        $n = count($ids);
        if ($n === 0) {
            return [];
        }
        // Work on parallel index arrays for speed.
        $xs = [];
        $ys = [];
        foreach ($ids as $i => $id) {
            $xs[$i] = $points[$id][0];
            $ys[$i] = $points[$id][1];
        }
        if ($n === 1) {
            return [$ids[0] => [$pad, $pad]];
        }

        // 1. Uniform scale from the *typical* nearest-neighbour distance. Take each
        // node's nearest neighbour, then the chosen percentile across them (median by
        // default) - robust to a single tight pair that would otherwise over-spread
        // the whole map. The declump (step 2) clears the closer-than-typical pairs.
        $nn = [];
        for ($i = 0; $i < $n; $i++) {
            $best = INF;
            for ($j = 0; $j < $n; $j++) {
                if ($i === $j) {
                    continue;
                }
                $d = hypot($xs[$i] - $xs[$j], $ys[$i] - $ys[$j]);
                if ($d > 0.5 && $d < $best) {
                    $best = $d;
                }
            }
            if (is_finite($best)) {
                $nn[] = $best;
            }
        }
        sort($nn);
        $refNN = $nn === [] ? null : $nn[min(count($nn) - 1, (int) floor(count($nn) * $percentile))];
        $scale = $refNN !== null ? min($maxScale, max(1.0, $minSpacing / $refNN)) : 1.0;
        $minX = min($xs);
        $minY = min($ys);
        for ($i = 0; $i < $n; $i++) {
            $xs[$i] = ($xs[$i] - $minX) * $scale;
            $ys[$i] = ($ys[$i] - $minY) * $scale;
        }

        // 2. AABB declump - separate overlapping pairs (ties broken deterministically
        // by index so the result is stable across re-imports).
        for ($iter = 0; $iter < $iterations; $iter++) {
            $moved = false;
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $dx = $xs[$j] - $xs[$i];
                    $dy = $ys[$j] - $ys[$i];
                    $ox = $cellW - abs($dx);
                    $oy = $cellH - abs($dy);
                    if ($ox <= 0 || $oy <= 0) {
                        continue; // no overlap on at least one axis
                    }
                    if ($ox <= $oy) { // push apart on X (least penetration)
                        $push = $ox / 2 + 0.5;
                        $dir = $dx < 0 ? -1 : ($dx > 0 ? 1 : ($i < $j ? -1 : 1));
                        $xs[$i] -= $dir * $push;
                        $xs[$j] += $dir * $push;
                    } else { // push apart on Y
                        $push = $oy / 2 + 0.5;
                        $dir = $dy < 0 ? -1 : ($dy > 0 ? 1 : ($i < $j ? -1 : 1));
                        $ys[$i] -= $dir * $push;
                        $ys[$j] += $dir * $push;
                    }
                    $moved = true;
                }
            }
            if (! $moved) {
                break;
            }
        }

        // Re-origin to a padded top-left and round to whole pixels.
        $shiftX = $pad - min($xs);
        $shiftY = $pad - min($ys);
        $out = [];
        foreach ($ids as $i => $id) {
            $out[$id] = [round($xs[$i] + $shiftX), round($ys[$i] + $shiftY)];
        }

        return $out;
    }

    // --- Links ------------------------------------------------------------

    /** Stub if_index base - well above any real SNMP if_index, so no collision. */
    private const STUB_IF_BASE = 1_000_000;

    private function importLinks(): void
    {
        if (! CsvReader::exists($this->path('links.csv'))) {
            return;
        }

        $created = 0;
        $skipped = 0;
        foreach (CsvReader::rows($this->path('links.csv')) as $r) {
            $aDeviceId = $this->deviceIdMap[$r['a_deviceID']] ?? null;
            $bDeviceId = $this->deviceIdMap[$r['b_deviceID']] ?? null;
            if ($aDeviceId === null || $bDeviceId === null || $aDeviceId === $bDeviceId) {
                $skipped++;

                continue;
            }

            // A end: the Dude-monitored interface (real if_index).
            $aIfaceId = $this->ifaceIdMap[$r['a_deviceID'].':'.$r['a_if_index']] ?? null;
            if ($aIfaceId === null) {
                $skipped++;

                continue;
            }

            // B end: Dude tracks only one interface, so the peer interface is unknown
            // -> best-effort stub (named for the A end, idempotent on re-import).
            $bIfaceId = $this->stubPeerInterface($bDeviceId, (string) ($r['a_device'] ?? 'peer'));

            $exists = DB::table('links')
                ->where('a_interface_id', $aIfaceId)->where('b_interface_id', $bIfaceId)
                ->exists();
            if ($exists) {
                continue;
            }

            try {
                DB::table('links')->insert([
                    'a_device_id' => $aDeviceId, 'a_interface_id' => $aIfaceId,
                    'b_device_id' => $bDeviceId, 'b_interface_id' => $bIfaceId,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $created++;
            } catch (\Throwable $e) {
                $skipped++; // trigger/unique violation - leave the rest of the import intact
            }
        }

        $this->summary['links'] = ['created' => $created, 'skipped' => $skipped];
    }

    /** Find-or-create a placeholder interface on the peer device for a link end. */
    private function stubPeerInterface(int $deviceId, string $aDeviceName): int
    {
        $name = '-> '.$aDeviceName;
        $existing = NetworkInterface::where('device_id', $deviceId)->where('name', $name)->first();
        if ($existing !== null) {
            return $existing->id;
        }

        $maxStub = (int) NetworkInterface::where('device_id', $deviceId)
            ->where('if_index', '>=', self::STUB_IF_BASE)->max('if_index');
        $ifIndex = max(self::STUB_IF_BASE, $maxStub + 1);

        return NetworkInterface::create([
            'device_id' => $deviceId,
            'if_index' => $ifIndex,
            'name' => $name,
        ])->id;
    }

    // --- Outages ----------------------------------------------------------

    private function importOutages(): void
    {
        if (! CsvReader::exists($this->path('outages.csv'))) {
            return;
        }

        $count = 0;
        foreach (CsvReader::rows($this->path('outages.csv')) as $r) {
            $deviceId = $this->deviceIdMap[$r['deviceID']] ?? null;
            $startedAt = $this->parseTs($r['time_utc'] ?? '');
            if ($deviceId === null || $startedAt === null) {
                continue;
            }
            $duration = is_numeric($r['duration_s'] ?? '') ? (int) $r['duration_s'] : null;
            $endedAt = $duration !== null ? $startedAt->copy()->addSeconds($duration) : null;

            DB::table('outages')->updateOrInsert(
                ['device_id' => $deviceId, 'started_at' => $startedAt->format('Y-m-d H:i:s')],
                [
                    'ended_at' => $endedAt?->format('Y-m-d H:i:s'),
                    'duration_s' => $duration,
                    'cause' => trim(($r['service'] ?? '') ?: ($r['status'] ?? '')) ?: null,
                    'updated_at' => now(), 'created_at' => now(),
                ],
            );
            $count++;
        }
        $this->summary['outages'] = $count;
    }

    // --- History (chart_values_* -> interface_samples) ---------------------

    private function importHistory(): void
    {
        // probeID -> [interfaceId, direction] from the enriched probes.csv.
        $probeIface = [];
        if (CsvReader::exists($this->path('probes.csv'))) {
            foreach (CsvReader::rows($this->path('probes.csv')) as $r) {
                if (($r['kind'] ?? '') !== 'interface' || ($r['iface_direction'] ?? '') === '') {
                    continue;
                }
                $ifaceId = $this->ifaceIdMap[$r['deviceID'].':'.$r['if_index']] ?? null;
                if ($ifaceId !== null) {
                    $probeIface[$r['probeID']] = [$ifaceId, $r['iface_direction']];
                }
            }
        }
        if ($probeIface === []) {
            $this->summary['history'] = ['samples' => 0, 'note' => 'no interface probes matched'];

            return;
        }

        $bands = (array) config('mymate.import.history_bands', []);
        $batchSize = max(500, (int) config('mymate.import.history_batch', 5000));
        $now = now();
        $partitions = [];     // 'Ymd' already-ensured
        $buffer = [];
        $total = 0;
        $minTs = null;

        // Byte-based progress/ETA across all band files (no costly pre-count).
        $files = [];
        $totalBytes = 0;
        foreach ($bands as $table => $maxAgeDays) {
            $csv = $this->path($table.'.csv');
            if (CsvReader::exists($csv)) {
                $size = max(1, (int) filesize($csv));
                $files[$table] = $size;
                $totalBytes += $size;
            }
        }
        $bytesDone = 0;
        $startedAt = microtime(true);
        // Track each interface's most-recent util per direction so we can seed the
        // *live* interfaces.util_in/out columns - the map reads those, so without this
        // imported links render grey until the first live poll (which may never run).
        $latestUtil = []; // ifaceId => ['in_ts'=>int,'in'=>float,'out_ts'=>int,'out'=>float]

        foreach ($files as $table => $size) {
            $csv = $this->path($table.'.csv');
            // Recency band: this resolution only covers [olderBound, youngerBound).
            $youngerBound = $this->bandYoungerBound($table, $bands, $now); // newer edge (exclusive) - covered by a finer band
            $maxAgeDays = (int) ($bands[$table] ?? 0);
            $olderBound = $maxAgeDays > 0 ? $now->copy()->subDays($maxAgeDays) : null;

            foreach (CsvReader::rowsTell($csv) as [$r, $tell]) {
                $pi = $probeIface[$r['sourceID']] ?? null;
                if ($pi !== null && ($r['value'] ?? '') !== '') {
                    $ts = $this->parseTs($r['time_utc'] ?? '');
                    if ($ts !== null
                        && ! ($youngerBound !== null && $ts->greaterThanOrEqualTo($youngerBound))
                        && ! ($olderBound !== null && $ts->lessThan($olderBound))) {
                        [$ifaceId, $dir] = $pi;
                        $bps = (float) $r['value'];
                        $speedMbps = $this->ifaceSpeed[$ifaceId] ?? null;
                        $util = ($speedMbps !== null && $speedMbps > 0)
                            ? round($bps / ($speedMbps * 1_000_000) * 100, 3)
                            : null;

                        // Directional half-row - the bucketed read API averages in/out
                        // per bucket (NULLs ignored), so tx & rx need not be merged here.
                        $buffer[] = [
                            'interface_id' => $ifaceId,
                            'ts' => $ts->format('Y-m-d H:i:s'),
                            'bps_in' => $dir === 'rx' ? $bps : null,
                            'bps_out' => $dir === 'tx' ? $bps : null,
                            'util_in' => $dir === 'rx' ? $util : null,
                            'util_out' => $dir === 'tx' ? $util : null,
                        ];
                        $this->ensurePartition($ts, $partitions);
                        $minTs = $minTs === null || $ts->lessThan($minTs) ? $ts : $minTs;

                        // Remember the newest sample per (interface, direction) to seed the
                        // live columns - bps always (throughput shows without a speed), util
                        // only when computable.
                        $epoch = $ts->getTimestamp();
                        $key = $dir === 'rx' ? 'in' : 'out';
                        if (! isset($latestUtil[$ifaceId][$key.'_ts']) || $epoch > $latestUtil[$ifaceId][$key.'_ts']) {
                            $latestUtil[$ifaceId][$key.'_ts'] = $epoch;
                            $latestUtil[$ifaceId][$key] = $util;          // may be null (no speed)
                            $latestUtil[$ifaceId][$key.'_bps'] = $bps;
                        }

                        if (count($buffer) >= $batchSize) {
                            $total += $this->flushHistory($buffer);
                            $buffer = [];
                            $this->progress->checkCancelled();
                            $frac = $totalBytes > 0 ? ($bytesDone + $tell) / $totalBytes : 0.0;
                            $this->progress->history($frac, microtime(true) - $startedAt,
                                number_format($total).' samples imported');
                        }
                    }
                }
            }
            $bytesDone += $size;
        }
        if ($buffer !== []) {
            $total += $this->flushHistory($buffer);
        }

        // Seed the live util columns from each interface's latest sample so imported
        // links show their last-known throughput immediately (the live poller, if it
        // ever runs against these devices, overwrites this on the next tick).
        $seeded = 0;
        foreach ($latestUtil as $ifaceId => $u) {
            $patch = [];
            if (isset($u['in_ts'])) {
                $patch['util_in'] = $u['in'];                       // null when no speed
                $patch['bps_in'] = (int) round($u['in_bps']);
            }
            if (isset($u['out_ts'])) {
                $patch['util_out'] = $u['out'];
                $patch['bps_out'] = (int) round($u['out_bps']);
            }
            if ($patch !== []) {
                NetworkInterface::where('id', $ifaceId)->update($patch);
                $seeded++;
            }
        }

        // Raise retention so the maintenance job won't drop the imported span.
        if ($minTs !== null) {
            $spanDays = (int) ceil($minTs->diffInDays($now)) + 2;
            $current = $this->settings->getInt('history.retention_days', 14);
            if ($spanDays > $current) {
                $this->settings->set('history.retention_days', $spanDays);
                $this->summary['history_retention_days'] = $spanDays;
            }
        }

        $this->summary['history'] = ['samples' => $total, 'seeded_util' => $seeded];
    }

    /** Newer (exclusive) edge of a band = the older bound of the next-finer band. */
    private function bandYoungerBound(string $table, array $bands, Carbon $now): ?Carbon
    {
        $order = ['chart_values_raw', 'chart_values_10min', 'chart_values_2hour', 'chart_values_1day'];
        $idx = array_search($table, $order, true);
        if ($idx === false || $idx === 0) {
            return null; // finest resolution -> no younger edge
        }
        $finer = $order[$idx - 1];
        $finerAge = (int) ($bands[$finer] ?? 0);

        return $finerAge > 0 ? $now->copy()->subDays($finerAge) : null;
    }

    /** Ensure the daily partition for $ts exists (cached in $ensured by Ymd). */
    private function ensurePartition(Carbon $ts, array &$ensured): void
    {
        $day = $ts->format('Ymd');
        if (isset($ensured[$day])) {
            return;
        }
        $name = 'interface_samples_'.$day;
        $from = $ts->copy()->startOfDay()->format('Y-m-d 00:00:00');
        $to = $ts->copy()->startOfDay()->addDay()->format('Y-m-d 00:00:00');
        DB::statement("CREATE TABLE IF NOT EXISTS \"{$name}\" PARTITION OF interface_samples FOR VALUES FROM ('{$from}') TO ('{$to}')");
        $ensured[$day] = true;
    }

    private function flushHistory(array $rows): int
    {
        try {
            DB::table('interface_samples')->insert($rows);

            return count($rows);
        } catch (\Throwable $e) {
            EngineLog::warning('import: history batch failed', ['error' => $e->getMessage(), 'rows' => count($rows)]);

            return 0;
        }
    }

    // --- helpers ----------------------------------------------------------

    private function path(string $file): string
    {
        return $this->dir.'/'.$file;
    }

    private function bpsToMbps(string $bps): ?int
    {
        if (! is_numeric($bps)) {
            return null;
        }
        $v = (int) round(((float) $bps) / 1_000_000);

        return $v > 0 ? $v : null;
    }

    private function parseTs(string $value): ?Carbon
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        try {
            return Carbon::createFromFormat('Y-m-d H:i:s', $value, 'UTC');
        } catch (\Throwable) {
            return null;
        }
    }
}
