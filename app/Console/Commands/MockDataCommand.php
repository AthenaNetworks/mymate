<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Models\DeviceMapPosition;
use App\Models\Link;
use App\Models\Map;
use App\Models\NetworkInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Demo data: a self-contained "Mock Lab" map with a small device tree, interfaces
 * carrying fake utilisation across the whole green->red ramp, and links between them -
 * so the map / inspector / colour ramp can be eyeballed without real gear.
 *
 * Mock devices are created with monitored=false (and mgmt IPs in RFC 5737 TEST-NET-2,
 * 198.51.100.0/24) so the ping + throughput loops skip them - their fake status/util
 * survives. `--clear` removes the map and devices again; the device cascade drops
 * their interfaces, links and per-map positions.
 */
class MockDataCommand extends Command
{
    protected $signature = 'mymate:mock {--clear : Remove the mock map + devices instead of creating them}';

    protected $description = 'Seed a demo "Mock Lab" map with sample devices/links to eyeball the UI (or --clear to remove it).';

    private const MAP_NAME = 'Mock Lab';

    private const IP_PREFIX = '198.51.100.'; // RFC 5737 TEST-NET-2 - reserved, never real gear

    public function handle(): int
    {
        return $this->option('clear') ? $this->clear() : $this->seed();
    }

    private function clear(): int
    {
        $ids = Device::query()
            ->where('monitored', false)
            ->where('mgmt_ip', 'like', self::IP_PREFIX.'%')
            ->pluck('id');

        Device::whereKey($ids)->delete(); // cascade -> interfaces, links, device_map_positions
        $maps = Map::where('name', self::MAP_NAME)->delete();

        $this->info("Removed {$ids->count()} mock device(s) and {$maps} mock map(s).");

        return self::SUCCESS;
    }

    private function seed(): int
    {
        $this->clear(); // idempotent - wipe any prior run first

        [$devices, $links] = $this->blueprint();

        DB::transaction(function () use ($devices, $links) {
            $map = Map::create(['name' => self::MAP_NAME, 'is_default' => false, 'position' => 999]);

            /** @var array<string, Device> $byKey */
            $byKey = [];
            /** @var array<string, NetworkInterface> $ifByKey  keyed "deviceKey:ifaceName" */
            $ifByKey = [];

            foreach ($devices as $d) {
                $device = Device::create([
                    'name' => $d['name'],
                    'mgmt_ip' => self::IP_PREFIX.$d['octet'],
                    'poll_method' => $d['poll'],
                    'credential_id' => null,
                    'status' => $d['status'],
                    'monitored' => false,
                    'last_change' => now()->subMinutes($d['octet']),
                    'device_type' => $d['type'],
                    'parent_device_id' => $d['parent'] !== null ? $byKey[$d['parent']]->id : null,
                    'vendor' => $d['vendor'] ?? null,
                    'model' => $d['model'] ?? null,
                    'os_version' => $d['os'] ?? null,
                    'uptime_seconds' => isset($d['updays']) ? $d['updays'] * 86400 : null,
                    'uptime_at' => isset($d['updays']) ? now() : null,
                    'map_x' => $d['x'],
                    'map_y' => $d['y'],
                ]);
                $byKey[$d['key']] = $device;

                DeviceMapPosition::create([
                    'device_id' => $device->id,
                    'map_id' => $map->id,
                    'x' => $d['x'],
                    'y' => $d['y'],
                ]);

                foreach ($d['ifaces'] as $if) {
                    $sp = $if['sp'];
                    $ui = $if['ui'];
                    $uo = $if['uo'];
                    $ifByKey[$d['key'].':'.$if['n']] = NetworkInterface::create([
                        'device_id' => $device->id,
                        'if_index' => $if['i'],
                        'name' => $if['n'],
                        'speed_mbps' => $sp, // read-only from SNMP in real life
                        'util_in' => $ui,
                        'util_out' => $uo,
                        // Derive a matching raw rate so the link edges (now coloured by
                        // raw bps / the link effective speed, ) still ramp.
                        'bps_in' => $ui !== null && $sp !== null ? (int) round($ui / 100 * $sp * 1_000_000) : null,
                        'bps_out' => $uo !== null && $sp !== null ? (int) round($uo / 100 * $sp * 1_000_000) : null,
                        'last_ts' => now(),
                    ]);
                }
            }

            foreach ($links as $l) {
                [$aKey, $aIf, $bKey, $bIf] = $l;
                Link::create([
                    'a_device_id' => $byKey[$aKey]->id,
                    'a_interface_id' => $ifByKey[$aKey.':'.$aIf]->id,
                    'b_device_id' => $byKey[$bKey]->id,
                    'b_interface_id' => $ifByKey[$bKey.':'.$bIf]->id,
                    // Optional per-link bandwidth override A->B / B->A.
                    'bw_ab_mbps' => $l[4] ?? null,
                    'bw_ba_mbps' => $l[5] ?? null,
                ]);
            }

            $this->info('Created the "'.self::MAP_NAME.'" map: '.count($devices).' devices, '.count($links).' links.');
        });

        $this->newLine();
        $this->line('  -> Open it from the map switcher (top-left of the map).');
        $this->line('  -> Remove it any time with:  php artisan mymate:mock --clear');

        return self::SUCCESS;
    }

    /**
     * A small ISP-style tree. `util_in`/`util_out` are chosen to spread links across the
     * whole colour ramp (green->amber->red), with one down node (grey edge) and one
     * asymmetric uplink (500dn/50up) to exercise the inspector pills.
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array{0:string,1:string,2:string,3:string}>}
     */
    private function blueprint(): array
    {
        $devices = [
            ['key' => 'inet', 'name' => 'INET-EDGE', 'octet' => 1, 'type' => 'internet', 'parent' => null, 'status' => 'up', 'poll' => 'snmp', 'x' => 420, 'y' => 0, 'model' => 'Upstream / ISP', 'ifaces' => [
                ['n' => 'ether1', 'i' => 1, 'sp' => 10000, 'ui' => 70, 'uo' => 91],
                ['n' => 'ether2', 'i' => 2, 'sp' => 10000, 'ui' => 40, 'uo' => 58],
            ]],
            ['key' => 'core1', 'name' => 'CORE-01', 'octet' => 2, 'type' => 'router', 'parent' => 'inet', 'status' => 'up', 'poll' => 'routeros', 'x' => 200, 'y' => 150, 'vendor' => 'MikroTik', 'model' => 'CCR2004-1G-12S+2XS', 'os' => '7.15.2', 'updays' => 88, 'ifaces' => [
                ['n' => 'sfp-sfpplus1', 'i' => 1, 'sp' => 10000, 'ui' => 88, 'uo' => 70],
                ['n' => 'ether2', 'i' => 2, 'sp' => 1000, 'ui' => 20, 'uo' => 34],
            ]],
            ['key' => 'core2', 'name' => 'CORE-02', 'octet' => 3, 'type' => 'router', 'parent' => 'inet', 'status' => 'up', 'poll' => 'routeros', 'x' => 640, 'y' => 150, 'vendor' => 'MikroTik', 'model' => 'CCR2004-1G-12S+2XS', 'os' => '7.15.2', 'updays' => 88, 'ifaces' => [
                ['n' => 'sfp-sfpplus1', 'i' => 1, 'sp' => 10000, 'ui' => 58, 'uo' => 40],
                ['n' => 'ether2', 'i' => 2, 'sp' => 1000, 'ui' => 9, 'uo' => 6],
            ]],
            ['key' => 'dista', 'name' => 'DIST-A', 'octet' => 10, 'type' => 'switch', 'parent' => 'core1', 'status' => 'up', 'poll' => 'routeros', 'x' => 110, 'y' => 310, 'vendor' => 'MikroTik', 'model' => 'CRS328-24P-4S+', 'os' => '7.14', 'updays' => 45, 'ifaces' => [
                ['n' => 'sfp1', 'i' => 1, 'sp' => 1000, 'ui' => 34, 'uo' => 20],
                ['n' => 'ether2', 'i' => 2, 'sp' => 1000, 'ui' => 0, 'uo' => 0],
                ['n' => 'ether3', 'i' => 3, 'sp' => 1000, 'ui' => 55, 'uo' => 73],
            ]],
            ['key' => 'distb', 'name' => 'DIST-B', 'octet' => 11, 'type' => 'switch', 'parent' => 'core2', 'status' => 'up', 'poll' => 'routeros', 'x' => 600, 'y' => 310, 'vendor' => 'MikroTik', 'model' => 'CRS328-24P-4S+', 'os' => '7.14', 'updays' => 45, 'ifaces' => [
                ['n' => 'sfp1', 'i' => 1, 'sp' => 1000, 'ui' => 9, 'uo' => 6],
                ['n' => 'ether2', 'i' => 2, 'sp' => 1000, 'ui' => 16, 'uo' => 12],
            ]],
            ['key' => 'ap', 'name' => 'AP-NORTH', 'octet' => 20, 'type' => 'ap', 'parent' => 'dista', 'status' => 'down', 'poll' => 'routeros', 'x' => 10, 'y' => 470, 'vendor' => 'MikroTik', 'model' => 'cAP ax', 'os' => '7.13', 'ifaces' => [
                ['n' => 'ether1', 'i' => 1, 'sp' => 1000, 'ui' => null, 'uo' => null],
            ]],
            ['key' => 'srv', 'name' => 'SRV-DB', 'octet' => 30, 'type' => 'server', 'parent' => 'dista', 'status' => 'up', 'poll' => 'snmp', 'x' => 230, 'y' => 470, 'vendor' => 'Dell', 'model' => 'PowerEdge R650', 'updays' => 120, 'ifaces' => [
                ['n' => 'eno1', 'i' => 1, 'sp' => 1000, 'ui' => 73, 'uo' => 55],
            ]],
            ['key' => 'cpe', 'name' => 'CPE-RAD', 'octet' => 40, 'type' => 'router', 'parent' => 'distb', 'status' => 'up', 'poll' => 'routeros', 'x' => 600, 'y' => 470, 'vendor' => 'MikroTik', 'model' => 'hAP ax3', 'os' => '7.15.1', 'updays' => 12, 'ifaces' => [
                // Gig port, but the circuit is asymmetric 500dn/50up - modelled on the LINK now.
                ['n' => 'ether1', 'i' => 1, 'sp' => 500, 'ui' => 16, 'uo' => 4], // 80Mdn / 20Mup
            ]],
        ];

        // [a deviceKey, a iface, b deviceKey, b iface, bw_ab_mbps?, bw_ba_mbps?]
        $links = [
            ['inet', 'ether1', 'core1', 'sfp-sfpplus1'],  // ~91% -> red
            ['inet', 'ether2', 'core2', 'sfp-sfpplus1'],  // ~58% -> amber
            ['core1', 'ether2', 'dista', 'sfp1'],         // ~34% -> yellow-green
            ['core2', 'ether2', 'distb', 'sfp1'],         // ~9%  -> green
            ['dista', 'ether2', 'ap', 'ether1'],          // down -> grey (AP-NORTH is down)
            ['dista', 'ether3', 'srv', 'eno1'],           // ~73% -> orange
            ['distb', 'ether2', 'cpe', 'ether1', 500, 50], // asymmetric 500dn/50up override on the link -> 20Mup = ~40%
        ];

        return [$devices, $links];
    }
}
