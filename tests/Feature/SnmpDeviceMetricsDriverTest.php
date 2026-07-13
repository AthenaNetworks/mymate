<?php

namespace Tests\Feature;

use App\Enums\PollMethod;
use App\Models\Credential;
use App\Models\Device;
use App\Services\Polling\DeviceMetricProfiles;
use App\Services\Polling\SnmpDeviceMetricsDriver;
use App\Services\Snmp\SnmpClientException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeSnmpClient;
use Tests\TestCase;

class SnmpDeviceMetricsDriverTest extends TestCase
{
    use RefreshDatabase;

    private function device(string $vendor = 'MikroTik', string $community = 'public-test'): Device
    {
        $credential = Credential::factory()->create(['snmp_community' => $community]);

        return Device::factory()->create([
            'poll_method' => PollMethod::Snmp,
            'credential_id' => $credential->id,
            'vendor' => $vendor,
        ]);
    }

    private function driver(FakeSnmpClient $snmp): SnmpDeviceMetricsDriver
    {
        return new SnmpDeviceMetricsDriver($snmp, new DeviceMetricProfiles);
    }

    public function test_reads_cpu_memory_and_temperature_for_a_mikrotik(): void
    {
        $hr = config('mymate.device_metrics.hrstorage');
        $snmp = new FakeSnmpClient;
        // CPU = average hrProcessorLoad across cores.
        $snmp->walks['.1.3.6.1.2.1.25.3.3.1.2'] = [1 => '10', 2 => '30'];
        // Memory = the physical-RAM storage row: 250 / 1000 = 25%. Swap row is ignored.
        $snmp->walks[$hr['descr']] = [1 => 'main memory', 2 => 'swap space'];
        $snmp->walks[$hr['size']] = [1 => '1000', 2 => '2000'];
        $snmp->walks[$hr['used']] = [1 => '250', 2 => '100'];
        // Temperature via the MikroTik scalar OID (the fake returns this map for any GET).
        $snmp->getsByCommunity['public-test'] = ['.1.3.6.1.4.1.14988.1.1.3.11.0' => '45'];

        $m = $this->driver($snmp)->sample($this->device());

        $this->assertSame(20.0, $m->cpuPct);
        $this->assertSame(25.0, $m->memUsedPct);
        $this->assertSame(45.0, $m->tempC);
    }

    public function test_unreadable_metrics_come_back_null_not_zero(): void
    {
        // Nothing scripted - walks return [], GET returns []. Every metric is null.
        $m = $this->driver(new FakeSnmpClient)->sample($this->device('Linux'));

        $this->assertNull($m->cpuPct);
        $this->assertNull($m->memUsedPct);
        $this->assertNull($m->tempC);
        $this->assertTrue($m->isEmpty());
    }

    public function test_default_profile_has_no_temperature_oid(): void
    {
        $hr = config('mymate.device_metrics.hrstorage');
        $snmp = new FakeSnmpClient;
        $snmp->walks['.1.3.6.1.2.1.25.3.3.1.2'] = [1 => '50'];
        $snmp->walks[$hr['descr']] = [1 => 'Physical memory'];
        $snmp->walks[$hr['size']] = [1 => '800'];
        $snmp->walks[$hr['used']] = [1 => '400'];
        // A temp value is present, but an unknown vendor uses the default profile which
        // declares no temp OID, so temperature stays null.
        $snmp->getsByCommunity['public-test'] = ['.1.3.6.1.4.1.14988.1.1.3.11.0' => '45'];

        $m = $this->driver($snmp)->sample($this->device('Acme'));

        $this->assertSame(50.0, $m->cpuPct);
        $this->assertSame(50.0, $m->memUsedPct);
        $this->assertNull($m->tempC);
    }

    public function test_clamps_a_bogus_cpu_reading_into_range(): void
    {
        $snmp = new FakeSnmpClient;
        $snmp->walks['.1.3.6.1.2.1.25.3.3.1.2'] = [1 => '250']; // impossible >100

        $this->assertSame(100.0, $this->driver($snmp)->sample($this->device())->cpuPct);
    }

    public function test_throws_when_the_device_has_no_snmp_community(): void
    {
        $device = Device::factory()->create(['poll_method' => PollMethod::Snmp, 'vendor' => 'MikroTik']);

        $this->expectException(SnmpClientException::class);
        $this->driver(new FakeSnmpClient)->sample($device);
    }
}
