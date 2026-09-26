<?php

namespace Tests\Feature;

use App\Enums\PollMethod;
use App\Models\Credential;
use App\Models\Device;
use App\Services\Polling\RouterOsDeviceMetricsDriver;
use App\Services\Polling\RouterOsWireless;
use App\Services\RouterOs\RouterOsClientException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeRouterOsClient;
use Tests\TestCase;

class RouterOsDeviceMetricsDriverTest extends TestCase
{
    use RefreshDatabase;

    private function device(): Device
    {
        $cred = Credential::factory()->routeros()->create();

        return Device::factory()->create(['poll_method' => PollMethod::RouterOs, 'credential_id' => $cred->id]);
    }

    public function test_reads_cpu_memory_and_temperature_over_the_api(): void
    {
        // The commands MUST be the /print form - a bare "/system/resource" traps with
        // "no such command" on real RouterOS (this test locks that in).
        $client = new FakeRouterOsClient(replies: [
            '/system/resource/print' => [['cpu-load' => '15', 'total-memory' => '1000', 'free-memory' => '250']],
            '/system/health/print' => [['name' => 'temperature', 'value' => '48']],
        ]);

        $m = (new RouterOsDeviceMetricsDriver($client))->sample($this->device());

        $this->assertSame(15.0, $m->cpuPct);
        $this->assertSame(75.0, $m->memUsedPct); // (1000 - 250) / 1000
        $this->assertSame(48.0, $m->tempC);
    }

    public function test_reads_routeros6_style_single_row_temperature(): void
    {
        $client = new FakeRouterOsClient(replies: [
            '/system/resource/print' => [['cpu-load' => '5', 'total-memory' => '200', 'free-memory' => '100']],
            '/system/health/print' => [['temperature' => '41', 'cpu-temperature' => '55']],
        ]);

        $m = (new RouterOsDeviceMetricsDriver($client))->sample($this->device());

        $this->assertSame(5.0, $m->cpuPct);
        $this->assertSame(50.0, $m->memUsedPct); // (200 - 100) / 200
        $this->assertSame(55.0, $m->tempC);      // hottest of the two sensors
    }

    public function test_updates_os_version_when_the_poll_finds_a_newer_one(): void
    {
        $device = $this->device();
        $device->update(['os_version' => '7.20.7']);
        $client = new FakeRouterOsClient(replies: [
            '/system/resource/print' => [['cpu-load' => '5', 'total-memory' => '100', 'free-memory' => '50', 'version' => '7.20.9 (stable)']],
        ]);

        (new RouterOsDeviceMetricsDriver($client))->sample($device);

        $this->assertSame('7.20.9', $device->fresh()->os_version);
    }

    public function test_reads_wireless_rf_from_the_registration_table(): void
    {
        $client = new FakeRouterOsClient(replies: [
            '/system/resource/print' => [['cpu-load' => '5', 'total-memory' => '100', 'free-memory' => '50']],
            '/interface/wireless/registration-table/print' => [
                ['signal-strength' => '-65dBm@6Mbps', 'signal-to-noise' => '30', 'tx-ccq' => '90'],
                ['signal-strength' => '-75', 'signal-to-noise' => '20', 'tx-ccq' => '80'],
            ],
        ]);

        $m = (new RouterOsDeviceMetricsDriver($client))->sample($this->device());

        $this->assertSame(-70.0, $m->signalDbm);     // avg(-65, -75)
        $this->assertSame(25.0, $m->snrDb);          // avg(30, 20)
        $this->assertSame(85.0, $m->ccqPct);         // avg(90, 80)
        $this->assertSame(2, $m->wirelessClients);
    }

    public function test_no_wireless_leaves_rf_null(): void
    {
        $client = new FakeRouterOsClient(replies: [
            '/system/resource/print' => [['cpu-load' => '5', 'total-memory' => '100', 'free-memory' => '50']],
            // no registration table (a wired router)
        ]);

        $m = (new RouterOsDeviceMetricsDriver($client))->sample($this->device());

        $this->assertNull($m->signalDbm);
        $this->assertNull($m->wirelessClients);
    }

    private const RES = ['cpu-load' => '5', 'total-memory' => '100', 'free-memory' => '50'];

    private const WIFI = '/interface/wifi/registration-table/print';

    private const WAVE2 = '/interface/wifiwave2/registration-table/print';

    private const LEGACY = '/interface/wireless/registration-table/print';

    private const CAPSMAN = '/caps-man/registration-table/print';

    /** A menu the board doesn't have, the way the real client surfaces the trap. */
    private static function noSuchCommand(): RouterOsClientException
    {
        return new RouterOsClientException('RouterOS query failed: no such command prefix');
    }

    private static function wlCommands(FakeRouterOsClient $client): array
    {
        return array_values(array_filter(
            $client->opened[array_key_last($client->opened)]->commands(),
            static fn (string $c) => in_array($c, [self::WIFI, self::WAVE2, self::LEGACY, self::CAPSMAN], true),
        ));
    }

    public function test_reads_the_routeros7_wifi_registration_table(): void
    {
        $client = new FakeRouterOsClient(replies: [
            '/system/resource/print' => [self::RES],
            self::WIFI => [
                ['mac-address' => 'AA:AA:AA:AA:AA:01', 'interface' => 'wifi1', 'signal' => '-50'],
                ['mac-address' => 'AA:AA:AA:AA:AA:02', 'interface' => 'wifi2', 'signal' => '-70'],
            ],
            self::WAVE2 => self::noSuchCommand(),
            self::LEGACY => self::noSuchCommand(),
        ]);

        $m = (new RouterOsDeviceMetricsDriver($client))->sample($this->device());

        $this->assertSame(-60.0, $m->signalDbm);
        $this->assertNull($m->snrDb);   // not in the wifi table
        $this->assertNull($m->ccqPct);  // wifi has no CCQ, never made up
        $this->assertSame(2, $m->wirelessClients);
        // wifi answered, so wifiwave2 (the old name of the same menu) is never asked for
        $this->assertSame([self::WIFI, self::LEGACY], self::wlCommands($client));
    }

    public function test_falls_back_to_wifiwave2_on_7_12_and_older(): void
    {
        $client = new FakeRouterOsClient(replies: [
            '/system/resource/print' => [self::RES],
            self::WIFI => self::noSuchCommand(),
            self::WAVE2 => [
                ['mac-address' => 'AA:AA:AA:AA:AA:01', 'signal' => '-61'],
                // no combined value on this one, only per chain -> strongest chain
                ['mac-address' => 'AA:AA:AA:AA:AA:02', 'signal-strength-ch0' => '-72', 'signal-strength-ch1' => '-68'],
                ['mac-address' => 'AA:AA:AA:AA:AA:03'], // no signal at all, still a client
            ],
            self::LEGACY => self::noSuchCommand(),
        ]);

        $m = (new RouterOsDeviceMetricsDriver($client))->sample($this->device());

        $this->assertSame(-64.5, $m->signalDbm); // avg(-61, -68)
        $this->assertNull($m->ccqPct);
        $this->assertSame(3, $m->wirelessClients);
        $this->assertSame([self::WIFI, self::WAVE2, self::LEGACY], self::wlCommands($client));
    }

    public function test_falls_back_to_legacy_wireless_and_keeps_ccq(): void
    {
        $client = new FakeRouterOsClient(replies: [
            '/system/resource/print' => [self::RES],
            self::WIFI => self::noSuchCommand(),
            self::WAVE2 => self::noSuchCommand(),
            self::LEGACY => [
                ['mac-address' => 'AA:AA:AA:AA:AA:01', 'signal-strength' => '-65dBm@6Mbps', 'signal-to-noise' => '30', 'tx-ccq' => '90'],
            ],
            self::CAPSMAN => [],
        ]);

        $m = (new RouterOsDeviceMetricsDriver($client))->sample($this->device());

        $this->assertSame(-65.0, $m->signalDbm);
        $this->assertSame(30.0, $m->snrDb);
        $this->assertSame(90.0, $m->ccqPct);
        $this->assertSame(1, $m->wirelessClients);
    }

    public function test_lib_trap_rows_count_as_a_missing_menu_not_a_client(): void
    {
        // evilfreelancer hands a !trap back as a row holding the =message=, not an exception.
        $trap = [['message' => 'no such command prefix']];
        $client = new FakeRouterOsClient(replies: [
            '/system/resource/print' => [self::RES],
            self::WIFI => $trap,
            self::WAVE2 => $trap,
            self::LEGACY => $trap,
        ]);

        $device = $this->device();
        $m = (new RouterOsDeviceMetricsDriver($client))->sample($device);

        $this->assertNull($m->wirelessClients);
        $this->assertNull($m->signalDbm);
        $this->assertSame([], Cache::get(RouterOsWireless::cacheKey($device)));
    }

    public function test_no_wireless_menus_at_all_never_breaks_the_poll(): void
    {
        $client = new FakeRouterOsClient(replies: [
            '/system/resource/print' => [['cpu-load' => '7', 'total-memory' => '100', 'free-memory' => '50']],
            self::WIFI => self::noSuchCommand(),
            self::WAVE2 => self::noSuchCommand(),
            self::LEGACY => self::noSuchCommand(),
        ]);

        $m = (new RouterOsDeviceMetricsDriver($client))->sample($this->device());

        $this->assertSame(7.0, $m->cpuPct);
        $this->assertNull($m->signalDbm);
        $this->assertNull($m->snrDb);
        $this->assertNull($m->ccqPct);
        $this->assertNull($m->wirelessClients);
    }

    public function test_caches_which_stacks_the_board_has(): void
    {
        $device = $this->device();
        $device->update(['os_version' => '7.16']);
        $client = new FakeRouterOsClient(replies: [
            '/system/resource/print' => [self::RES + ['version' => '7.16 (stable)']],
            self::WIFI => [['mac-address' => 'AA:AA:AA:AA:AA:01', 'signal' => '-50']],
            self::LEGACY => self::noSuchCommand(),
        ]);
        $driver = new RouterOsDeviceMetricsDriver($client);

        $driver->sample($device);
        $this->assertSame([self::WIFI, self::LEGACY], self::wlCommands($client));
        $this->assertSame(['wifi'], Cache::get('routeros:wl-stacks:'.$device->id.':7.16'));

        // second poll only asks for the menu that exists
        $m = $driver->sample($device);
        $this->assertSame([self::WIFI], self::wlCommands($client));
        $this->assertSame(1, $m->wirelessClients);

        // an upgrade changes the key, so the new version is probed again
        $client->replies['/system/resource/print'] = [self::RES + ['version' => '7.17 (stable)']];
        $driver->sample($device);
        $this->assertSame('7.17', $device->fresh()->os_version);
        $this->assertSame([self::WIFI, self::LEGACY], self::wlCommands($client));
    }

    public function test_a_cached_menu_that_disappears_is_probed_again(): void
    {
        $device = $this->device();
        Cache::put(RouterOsWireless::cacheKey($device), ['wireless'], 3600);
        $client = new FakeRouterOsClient(replies: [
            '/system/resource/print' => [self::RES],
            self::LEGACY => self::noSuchCommand(),
        ]);

        $m = (new RouterOsDeviceMetricsDriver($client))->sample($device);

        $this->assertNull($m->wirelessClients);
        $this->assertNull(Cache::get(RouterOsWireless::cacheKey($device)));
    }

    public function test_a_timeout_during_the_probe_is_not_cached(): void
    {
        $device = $this->device();
        $client = new FakeRouterOsClient(replies: [
            '/system/resource/print' => [self::RES],
            self::WIFI => new RouterOsClientException('RouterOS query failed: timeout'),
            self::WAVE2 => self::noSuchCommand(),
            self::LEGACY => self::noSuchCommand(),
        ]);

        (new RouterOsDeviceMetricsDriver($client))->sample($device);

        $this->assertNull(Cache::get(RouterOsWireless::cacheKey($device)));
    }

    public function test_capsman_controller_counts_every_cap_client_once(): void
    {
        // A hAP ac2 on 7.x as the controller: its own legacy radio, a wifi CAPsMAN for two cAP ax
        // (remote cap interfaces in the same /interface/wifi table) and a legacy CAPsMAN cAP ac.
        $client = new FakeRouterOsClient(replies: [
            '/system/resource/print' => [self::RES],
            self::WIFI => [
                ['mac-address' => 'aa:aa:aa:aa:aa:01', 'interface' => 'cap-wifi1', 'signal' => '-50'],
                ['mac-address' => 'AA:AA:AA:AA:AA:02', 'interface' => 'cap-wifi3', 'signal' => '-60'],
            ],
            self::LEGACY => [
                ['mac-address' => 'AA:AA:AA:AA:AA:03', 'signal-strength' => '-70', 'signal-to-noise' => '25', 'tx-ccq' => '80'],
            ],
            self::CAPSMAN => [
                ['mac-address' => 'AA:AA:AA:AA:AA:04', 'interface' => 'cap1', 'rx-signal' => '-80'],
                // same station seen twice mid roam - counted once
                ['mac-address' => 'AA:AA:AA:AA:AA:01', 'interface' => 'cap2', 'rx-signal' => '-90'],
            ],
        ]);

        $m = (new RouterOsDeviceMetricsDriver($client))->sample($this->device());

        $this->assertSame(4, $m->wirelessClients);
        $this->assertSame(-65.0, $m->signalDbm); // avg(-50, -60, -70, -80)
        $this->assertSame(25.0, $m->snrDb);      // only the legacy row has SNR
        $this->assertSame(80.0, $m->ccqPct);     // and CCQ
        $this->assertSame([self::WIFI, self::LEGACY, self::CAPSMAN], self::wlCommands($client));
    }

    public function test_no_health_data_leaves_temperature_null(): void
    {
        $client = new FakeRouterOsClient(replies: [
            '/system/resource/print' => [['cpu-load' => '3', 'total-memory' => '2000', 'free-memory' => '500']],
            // no /system/health/print (board has no sensors)
        ]);

        $m = (new RouterOsDeviceMetricsDriver($client))->sample($this->device());

        $this->assertSame(3.0, $m->cpuPct);
        $this->assertSame(75.0, $m->memUsedPct);
        $this->assertNull($m->tempC);
    }
}
