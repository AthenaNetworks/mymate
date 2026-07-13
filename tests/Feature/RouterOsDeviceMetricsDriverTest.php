<?php

namespace Tests\Feature;

use App\Enums\PollMethod;
use App\Models\Credential;
use App\Models\Device;
use App\Services\Polling\RouterOsDeviceMetricsDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
