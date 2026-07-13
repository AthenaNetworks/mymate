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
