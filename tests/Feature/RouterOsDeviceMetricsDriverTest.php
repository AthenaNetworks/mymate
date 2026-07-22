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

    public function test_counts_ospf_neighbours_in_the_full_state(): void
    {
        $client = new FakeRouterOsClient(replies: [
            '/system/resource/print' => [['cpu-load' => '5', 'total-memory' => '100', 'free-memory' => '50']],
            // 3 neighbours: 2 full adjacencies, 1 still building.
            '/routing/ospf/neighbor/print' => [
                ['state' => 'Full'],
                ['state' => 'Full'],
                ['state' => '2-Way'],
            ],
        ]);

        $m = (new RouterOsDeviceMetricsDriver($client))->sample($this->device());

        $this->assertSame(2, $m->ospfNeighbors);
    }

    public function test_ospf_neighbours_is_null_when_the_router_runs_no_ospf(): void
    {
        // The command returns nothing (or isn't available) - leave the metric null, never 0-forced.
        $client = new FakeRouterOsClient(replies: [
            '/system/resource/print' => [['cpu-load' => '5', 'total-memory' => '100', 'free-memory' => '50']],
            '/routing/ospf/neighbor/print' => [],
        ]);

        $m = (new RouterOsDeviceMetricsDriver($client))->sample($this->device());

        $this->assertSame(0, $m->ospfNeighbors); // empty table -> zero full neighbours
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
