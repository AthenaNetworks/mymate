<?php

namespace Tests\Feature;

use App\Actions\Devices\CaptureDeviceFacts;
use App\Enums\DeviceType;
use App\Enums\PollMethod;
use App\Models\Credential;
use App\Models\Device;
use App\Services\RouterOs\RouterOsClient;
use App\Services\Snmp\SnmpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeRouterOsClient;
use Tests\Support\FakeSnmpClient;
use Tests\TestCase;

class CaptureDeviceFactsTest extends TestCase
{
    use RefreshDatabase;

    public function test_routeros_facts_populate_vendor_model_uptime_and_type(): void
    {
        $cred = Credential::factory()->create(['type' => 'routeros', 'username' => 'admin', 'password' => 'pw']);
        $device = Device::factory()->create([
            'poll_method' => PollMethod::RouterOs,
            'credential_id' => $cred->id,
            'device_type' => DeviceType::Unknown,
        ]);

        $this->app->instance(RouterOsClient::class, new FakeRouterOsClient([
            '/system/resource/print' => [['board-name' => 'CCR2004-16-12S+', 'uptime' => '1w2d3h4m5s']],
            '/system/routerboard/print' => [['model' => 'CCR2004-16-12S+']],
        ]));

        app(CaptureDeviceFacts::class)($device);
        $device->refresh();

        $this->assertSame('MikroTik', $device->vendor);
        $this->assertSame('CCR2004-16-12S+', $device->model);
        $this->assertSame(604800 + 2 * 86400 + 3 * 3600 + 4 * 60 + 5, $device->uptime_seconds);
        $this->assertSame(DeviceType::Router, $device->device_type);
        $this->assertNotNull($device->uptime_at);
    }

    public function test_routeros_facts_capture_serial_cpu_and_ram(): void
    {
        $cred = Credential::factory()->create(['type' => 'routeros', 'username' => 'admin', 'password' => 'pw']);
        $device = Device::factory()->create(['poll_method' => PollMethod::RouterOs, 'credential_id' => $cred->id]);

        $this->app->instance(RouterOsClient::class, new FakeRouterOsClient([
            '/system/resource/print' => [[
                'board-name' => 'RB4011iGS+', 'cpu' => 'ARM', 'cpu-count' => '4', 'cpu-frequency' => '1400',
                'total-memory' => (string) (1024 * 1024 * 1024),
            ]],
            '/system/routerboard/print' => [['model' => 'RB4011iGS+', 'serial-number' => 'ABC123']],
        ]));

        app(CaptureDeviceFacts::class)($device);
        $device->refresh();

        $this->assertSame('ABC123', $device->serial);
        $this->assertSame('ARM 4-core @ 1.4 GHz', $device->cpu);
        $this->assertSame(1024 * 1024 * 1024, $device->ram_bytes);
    }

    public function test_snmp_facts_capture_model_serial_and_ram_from_entity_and_host_mibs(): void
    {
        $cred = Credential::factory()->create(['snmp_community' => 'public-test']);
        $device = Device::factory()->create(['poll_method' => PollMethod::Snmp, 'credential_id' => $cred->id]);

        $oids = config('mymate.snmp.oids');
        $snmp = new FakeSnmpClient;
        $snmp->getsByCommunity['public-test'] = [
            $oids['sys_descr'] => 'Ubiquiti NanoStation',
            $oids['sys_uptime'] => '100',
            $oids['hr_memory'] => '65536', // KB -> 64 MB
        ];
        $snmp->walks[$oids['ent_model']] = [1 => '', 2 => 'NanoStation M5'];
        $snmp->walks[$oids['ent_serial']] = [1 => 'SN-7788'];
        $this->app->instance(SnmpClient::class, $snmp);

        app(CaptureDeviceFacts::class)($device);
        $device->refresh();

        $this->assertSame('NanoStation M5', $device->model);
        $this->assertSame('SN-7788', $device->serial);
        $this->assertSame(65536 * 1024, $device->ram_bytes);
    }

    public function test_mikrotik_snmp_model_falls_back_to_sysdescr_when_entity_is_a_junk_board_id(): void
    {
        $cred = Credential::factory()->create(['snmp_community' => 'public-test']);
        $device = Device::factory()->create(['poll_method' => PollMethod::Snmp, 'credential_id' => $cred->id]);

        $oids = config('mymate.snmp.oids');
        $snmp = new FakeSnmpClient;
        $snmp->getsByCommunity['public-test'] = [
            $oids['sys_descr'] => 'RouterOS RB5009UPr+S+',
            $oids['sys_uptime'] => '100',
        ];
        // MikroTik's ENTITY-MIB model row is a useless hex board id - it must be rejected and
        // the real board name recovered from sysDescr instead.
        $snmp->walks[$oids['ent_model']] = [1 => '0x0002'];
        $this->app->instance(SnmpClient::class, $snmp);

        app(CaptureDeviceFacts::class)($device);

        $this->assertSame('RB5009UPr+S+', $device->fresh()->model);
    }

    public function test_mikrotik_snmp_model_from_modern_sysdescr_with_version_and_channel(): void
    {
        $cred = Credential::factory()->create(['snmp_community' => 'public-test']);
        $device = Device::factory()->create(['poll_method' => PollMethod::Snmp, 'credential_id' => $cred->id]);

        $oids = config('mymate.snmp.oids');
        $snmp = new FakeSnmpClient;
        // Newer RouterOS appends the version + channel after the board name; the model is still
        // the token right after "RouterOS" (this exact string comes off a live RB5009).
        $snmp->getsByCommunity['public-test'] = [
            $oids['sys_descr'] => 'RouterOS RB5009UPr+S+ 7.23.2 (stable)',
            $oids['sys_uptime'] => '100',
        ];
        $snmp->walks[$oids['ent_model']] = [1 => '0x0002'];
        $this->app->instance(SnmpClient::class, $snmp);

        app(CaptureDeviceFacts::class)($device);

        $this->assertSame('RB5009UPr+S+', $device->fresh()->model);
    }

    public function test_mikrotik_modelless_sysdescr_does_not_capture_a_version_as_the_model(): void
    {
        $cred = Credential::factory()->create(['snmp_community' => 'public-test']);
        $device = Device::factory()->create(['poll_method' => PollMethod::Snmp, 'credential_id' => $cred->id]);

        $oids = config('mymate.snmp.oids');
        $snmp = new FakeSnmpClient;
        // A CHR-less build can report just "RouterOS <version>" - the version must not become
        // the model (it isn't a product name and can't map to a photo).
        $snmp->getsByCommunity['public-test'] = [
            $oids['sys_descr'] => 'RouterOS 7.23.2 (stable)',
            $oids['sys_uptime'] => '100',
        ];
        $this->app->instance(SnmpClient::class, $snmp);

        app(CaptureDeviceFacts::class)($device);

        $this->assertNull($device->fresh()->model);
        $this->assertSame('MikroTik', $device->fresh()->vendor);
    }

    public function test_snmp_facts_populate_vendor_and_uptime(): void
    {
        $cred = Credential::factory()->create(['snmp_community' => 'public-test']);
        $device = Device::factory()->create(['poll_method' => PollMethod::Snmp, 'credential_id' => $cred->id]);

        $oids = config('mymate.snmp.oids');
        $snmp = new FakeSnmpClient;
        $snmp->getsByCommunity['public-test'] = [
            $oids['sys_descr'] => 'RouterOS RB750Gr3 7.14',
            $oids['sys_uptime'] => '123456', // TimeTicks (1/100 s) -> 1234 s
        ];
        $this->app->instance(SnmpClient::class, $snmp);

        app(CaptureDeviceFacts::class)($device);
        $device->refresh();

        $this->assertSame('MikroTik', $device->vendor);
        $this->assertSame(1234, $device->uptime_seconds);
        $this->assertSame(DeviceType::Router, $device->device_type);
    }

    public function test_missing_credential_is_a_safe_noop(): void
    {
        $device = Device::factory()->create(['poll_method' => PollMethod::Snmp, 'credential_id' => null]);

        app(CaptureDeviceFacts::class)($device);
        $device->refresh();

        $this->assertNull($device->vendor);
        $this->assertNull($device->uptime_seconds);
    }

    public function test_it_does_not_overwrite_a_manual_device_type(): void
    {
        $cred = Credential::factory()->create(['type' => 'routeros', 'username' => 'admin', 'password' => 'pw']);
        $device = Device::factory()->create([
            'poll_method' => PollMethod::RouterOs,
            'credential_id' => $cred->id,
            'device_type' => DeviceType::Switch, // operator override
        ]);

        $this->app->instance(RouterOsClient::class, new FakeRouterOsClient([
            '/system/resource/print' => [['board-name' => 'CCR2004', 'uptime' => '5m']],
            '/system/routerboard/print' => [['model' => 'CCR2004']],
        ]));

        app(CaptureDeviceFacts::class)($device);
        $device->refresh();

        $this->assertSame(DeviceType::Switch, $device->device_type); // unchanged
        $this->assertSame('CCR2004', $device->model);                // other facts still captured
        $this->assertSame(300, $device->uptime_seconds);
    }
}
