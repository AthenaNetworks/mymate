<?php

namespace Tests\Feature;

use App\Enums\PollMethod;
use App\Models\Credential;
use App\Models\Device;
use App\Services\Polling\RouterOsThroughputDriver;
use App\Services\RouterOs\RouterOsClientException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeRouterOsClient;
use Tests\TestCase;

class RouterOsThroughputDriverTest extends TestCase
{
    use RefreshDatabase;

    private function routerOsDevice(): Device
    {
        $credential = Credential::factory()->routeros()->create();

        return Device::factory()->create([
            'poll_method' => PollMethod::RouterOs,
            'credential_id' => $credential->id,
        ]);
    }

    private function fakeClient(): FakeRouterOsClient
    {
        return new FakeRouterOsClient(replies: [
            '/interface/print' => [
                ['.id' => '*1', 'name' => 'ether1', 'type' => 'ether'],
                ['.id' => '*A', 'name' => 'ether2', 'type' => 'ether'], // hex A -> 10
            ],
            // print gives names; the negotiated rate comes from ethernet/monitor.
            '/interface/ethernet/print' => [
                ['name' => 'ether1'],
                ['name' => 'ether2'],
            ],
            '/interface/ethernet/monitor' => [
                ['name' => 'ether1', 'status' => 'link-ok', 'rate' => '1Gbps'],
                ['name' => 'ether2', 'status' => 'link-ok', 'rate' => '10Gbps'],
            ],
            '/interface/monitor-traffic' => [
                ['name' => 'ether1', 'rx-bits-per-second' => '1000000', 'tx-bits-per-second' => '500000'],
                ['name' => 'ether2', 'rx-bits-per-second' => '0', 'tx-bits-per-second' => '0'],
            ],
        ]);
    }

    public function test_discover_maps_print_and_ethernet_rate_to_ifindex(): void
    {
        $found = (new RouterOsThroughputDriver($this->fakeClient()))->discover($this->routerOsDevice());

        $this->assertSame([
            ['if_index' => 1, 'name' => 'ether1', 'description' => null, 'speed_mbps' => 1000],
            ['if_index' => 10, 'name' => 'ether2', 'description' => null, 'speed_mbps' => 10000],
        ], $found);
    }

    public function test_discover_captures_interface_comment_as_description(): void
    {
        $client = new FakeRouterOsClient(replies: [
            '/interface/print' => [
                ['.id' => '*1', 'name' => 'ether1', 'type' => 'ether', 'comment' => 'Uplink to core'],
                ['.id' => '*2', 'name' => 'ether2', 'type' => 'ether'], // no comment
            ],
            '/interface/ethernet/print' => [['name' => 'ether1'], ['name' => 'ether2']],
            '/interface/ethernet/monitor' => [
                ['name' => 'ether1', 'rate' => '1Gbps'],
                ['name' => 'ether2', 'rate' => '1Gbps'],
            ],
        ]);

        $found = (new RouterOsThroughputDriver($client))->discover($this->routerOsDevice());

        $this->assertSame('Uplink to core', $found[0]['description']);
        $this->assertNull($found[1]['description']);
    }

    public function test_sample_returns_direct_bps_rates_keyed_by_ifindex(): void
    {
        $samples = (new RouterOsThroughputDriver($this->fakeClient()))->sample($this->routerOsDevice());

        $this->assertTrue($samples[1]->isDirectRate());
        $this->assertSame(1_000_000.0, $samples[1]->inBps);
        $this->assertSame(500_000.0, $samples[1]->outBps);
        $this->assertNull($samples[1]->inOctets); // no counter state on the RouterOS path
        $this->assertArrayHasKey(10, $samples);
    }

    public function test_sample_captures_oper_status_from_the_running_flag(): void
    {
        // ether2 has a cable pulled: running=false, and monitor-traffic omits it entirely.
        $client = new FakeRouterOsClient(replies: [
            '/interface/print' => [
                ['.id' => '*1', 'name' => 'ether1', 'type' => 'ether', 'running' => 'true'],
                ['.id' => '*A', 'name' => 'ether2', 'type' => 'ether', 'running' => 'false'],
            ],
            '/interface/monitor-traffic' => [
                ['name' => 'ether1', 'rx-bits-per-second' => '1000000', 'tx-bits-per-second' => '500000'],
                // ether2 not reported (down) - the driver must still record it as down.
            ],
        ]);

        $samples = (new RouterOsThroughputDriver($client))->sample($this->routerOsDevice());

        $this->assertTrue($samples[1]->operUp);         // ether1 up
        $this->assertFalse($samples[10]->operUp);       // ether2 down, even though monitor-traffic skipped it
        $this->assertSame(0.0, $samples[10]->inBps);
    }

    public function test_down_ports_are_recorded_even_when_monitor_traffic_fails(): void
    {
        // A monitor-traffic failure (a disabled interface in the batch trips the whole call on
        // some RouterOS versions) must not lose the per-port down detection read from `running`.
        // GitHub #22: the alert-critical up/down state comes from /interface/print, not traffic.
        $client = new FakeRouterOsClient(replies: [
            '/interface/print' => [
                ['.id' => '*1', 'name' => 'ether1', 'type' => 'ether', 'running' => 'true'],
                ['.id' => '*A', 'name' => 'ether2', 'type' => 'ether', 'running' => 'false'],
            ],
            '/interface/monitor-traffic' => new RouterOsClientException('interface disabled'),
        ]);

        $samples = (new RouterOsThroughputDriver($client))->sample($this->routerOsDevice());

        $this->assertArrayHasKey(10, $samples);   // ether2 (hex A) still present
        $this->assertFalse($samples[10]->operUp); // and still detected as down
    }

    public function test_missing_routeros_credential_throws(): void
    {
        $credential = Credential::factory()->create(); // snmp type, no username
        $device = Device::factory()->create([
            'poll_method' => PollMethod::RouterOs,
            'credential_id' => $credential->id,
        ]);

        $this->expectException(RouterOsClientException::class);
        (new RouterOsThroughputDriver(new FakeRouterOsClient))->sample($device);
    }

    public function test_connect_failure_propagates_fast(): void
    {
        $client = new FakeRouterOsClient(failOpenWith: new RouterOsClientException('connect timeout'));

        $this->expectException(RouterOsClientException::class);
        (new RouterOsThroughputDriver($client))->discover($this->routerOsDevice());
    }

    public function test_parses_ethernet_speed_to_mbps(): void
    {
        $this->assertSame(1000, RouterOsThroughputDriver::parseSpeedMbps('1Gbps'));
        $this->assertSame(100, RouterOsThroughputDriver::parseSpeedMbps('100Mbps'));
        $this->assertSame(10000, RouterOsThroughputDriver::parseSpeedMbps('10Gbps'));
        $this->assertSame(2500, RouterOsThroughputDriver::parseSpeedMbps('2.5Gbps'));
        $this->assertNull(RouterOsThroughputDriver::parseSpeedMbps(''));
        $this->assertNull(RouterOsThroughputDriver::parseSpeedMbps('auto'));
    }
}
