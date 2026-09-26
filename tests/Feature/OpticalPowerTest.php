<?php

namespace Tests\Feature;

use App\Actions\Agent\DispatchAgentJobs;
use App\Actions\Agent\IngestAgentResults;
use App\Actions\Alerts\EvaluateAlerts;
use App\Actions\Polling\PollDeviceMetrics;
use App\Actions\Polling\RecordOpticalPower;
use App\Enums\AlertCondition;
use App\Enums\DeviceStatus;
use App\Enums\PollMethod;
use App\Models\Agent;
use App\Models\AlertEvent;
use App\Models\AlertPolicy;
use App\Models\Credential;
use App\Models\Device;
use App\Models\NetworkInterface;
use App\Services\Polling\DeviceMetrics;
use App\Services\Polling\DeviceMetricsDriver;
use App\Services\Polling\DeviceMetricsDriverFactory;
use App\Services\Polling\OpticalPowerReader;
use App\Services\Polling\OpticalReading;
use App\Services\RouterOs\RouterOsClient;
use App\Services\RouterOs\RouterOsClientException;
use App\Services\Snmp\SnmpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeRouterOsClient;
use Tests\Support\FakeSnmpClient;
use Tests\TestCase;

/**
 * Fibre / SFP optical Tx/Rx power (GitHub #11): read over the RouterOS API and MikroTik SNMP,
 * stored per interface, shown via the interface API, alertable against a dBm threshold, and
 * carried through remote agents.
 */
class OpticalPowerTest extends TestCase
{
    use RefreshDatabase;

    private const RX = '.1.3.6.1.4.1.14988.1.1.19.1.1.10';

    private const TX = '.1.3.6.1.4.1.14988.1.1.19.1.1.9';

    private const NAME = '.1.3.6.1.4.1.14988.1.1.19.1.1.2';

    private function snmpMikrotik(): Device
    {
        $cred = Credential::factory()->create(['snmp_community' => 'public-test']);

        return Device::factory()->create(['poll_method' => PollMethod::Snmp, 'credential_id' => $cred->id, 'vendor' => 'MikroTik', 'status' => DeviceStatus::Up]);
    }

    private function routerOsDevice(): Device
    {
        $cred = Credential::factory()->routeros()->create();

        return Device::factory()->create(['poll_method' => PollMethod::RouterOs, 'credential_id' => $cred->id, 'status' => DeviceStatus::Up]);
    }

    private function reader(?FakeSnmpClient $snmp = null, ?FakeRouterOsClient $ros = null): OpticalPowerReader
    {
        $this->app->instance(SnmpClient::class, $snmp ?? new FakeSnmpClient);
        $this->app->instance(RouterOsClient::class, $ros ?? new FakeRouterOsClient);

        return app(OpticalPowerReader::class);
    }

    // --- reading ---------------------------------------------------------------

    public function test_reads_the_mikrotik_snmp_optical_table_in_dbm(): void
    {
        $snmp = new FakeSnmpClient;
        $snmp->walks[self::RX] = [5 => '-5123', 6 => '-40000'];
        $snmp->walks[self::TX] = [5 => '-2250'];
        $snmp->walks[self::NAME] = [5 => 'sfp1'];

        $readings = $this->reader($snmp)->read($this->snmpMikrotik());

        $this->assertCount(2, $readings);
        [$a, $b] = $readings;
        $this->assertSame([5, 'sfp1', -5.12, -2.25], [$a->ifIndex, $a->name, $a->rxDbm, $a->txDbm]);
        $this->assertSame([6, null, -40.0, null], [$b->ifIndex, $b->name, $b->rxDbm, $b->txDbm]);
    }

    public function test_a_vendor_without_an_optical_profile_is_not_readable(): void
    {
        $device = $this->snmpMikrotik();
        $device->update(['vendor' => 'Cisco']);

        $this->assertNull($this->reader()->read($device));
    }

    public function test_reads_sfp_power_over_the_routeros_api(): void
    {
        $ros = new FakeRouterOsClient(replies: [
            '/interface/ethernet/print' => [['name' => 'ether1'], ['name' => 'sfp-sfpplus1'], ['name' => 'sfp2']],
            '/interface/ethernet/monitor' => [
                ['name' => 'ether1', 'rate' => '1Gbps'],
                ['name' => 'sfp-sfpplus1', 'sfp-rx-power' => '-7.321', 'sfp-tx-power' => '-1.998'],
                ['name' => 'sfp2', 'sfp-module-present' => 'false'],
            ],
        ]);

        $readings = $this->reader(ros: $ros)->read($this->routerOsDevice());

        $this->assertCount(1, $readings);
        $this->assertSame(['sfp-sfpplus1', -7.32, -2.0], [$readings[0]->name, $readings[0]->rxDbm, $readings[0]->txDbm]);
        // The monitor needs `once` or it streams forever.
        $monitor = collect($ros->opened[0]->queries)->firstWhere('command', '/interface/ethernet/monitor');
        $this->assertSame(['numbers' => 'ether1,sfp-sfpplus1,sfp2', 'once' => ''], $monitor['params']);
    }

    public function test_a_failed_routeros_read_is_null_not_empty(): void
    {
        $ros = new FakeRouterOsClient(failOpenWith: new RouterOsClientException('timeout'));

        $this->assertNull($this->reader(ros: $ros)->read($this->routerOsDevice()));
    }

    // --- storing ---------------------------------------------------------------

    public function test_record_matches_by_name_then_if_index_and_clears_removed_modules(): void
    {
        $device = $this->snmpMikrotik();
        $sfp1 = NetworkInterface::factory()->for($device)->create(['if_index' => 5, 'name' => 'sfp1']);
        $sfp2 = NetworkInterface::factory()->for($device)->create(['if_index' => 6, 'name' => 'sfp2']);
        $gone = NetworkInterface::factory()->for($device)->create(['if_index' => 7, 'name' => 'sfp3', 'optical_rx_dbm' => -3.0, 'optical_at' => now()]);

        $n = app(RecordOpticalPower::class)($device->id, [
            new OpticalReading(ifIndex: 99, name: 'sfp1', rxDbm: -5.1, txDbm: -2.2), // name wins over a wrong index
            new OpticalReading(ifIndex: 6, name: null, rxDbm: -18.0, txDbm: null),
            new OpticalReading(ifIndex: 42, name: 'nope', rxDbm: -1.0, txDbm: -1.0), // unknown port - dropped
        ]);

        $this->assertSame(2, $n);
        $this->assertSame(-5.1, $sfp1->refresh()->optical_rx_dbm);
        $this->assertSame(-2.2, $sfp1->optical_tx_dbm);
        $this->assertNotNull($sfp1->optical_at);
        $this->assertSame(-18.0, $sfp2->refresh()->optical_rx_dbm);
        $this->assertNull($sfp2->optical_tx_dbm);
        $this->assertNull($gone->refresh()->optical_rx_dbm); // module pulled - cleared
        $this->assertNull($gone->optical_at);
    }

    public function test_the_metrics_tick_stores_optical_power_and_keeps_it_on_a_failed_read(): void
    {
        $this->app->instance(DeviceMetricsDriverFactory::class, new class extends DeviceMetricsDriverFactory
        {
            public function __construct() {}

            public function for(Device $device): DeviceMetricsDriver
            {
                return new class implements DeviceMetricsDriver
                {
                    public function sample(Device $device): DeviceMetrics
                    {
                        return new DeviceMetrics(cpuPct: 5.0);
                    }
                };
            }
        });
        $snmp = new FakeSnmpClient;
        $snmp->walks[self::RX] = [5 => '-26500'];
        $snmp->walks[self::TX] = [5 => '-1000'];
        $this->reader($snmp);
        $device = $this->snmpMikrotik();
        $port = NetworkInterface::factory()->for($device)->create(['if_index' => 5, 'name' => 'sfp1']);

        app(PollDeviceMetrics::class)([$device->id]);

        $this->assertSame(-26.5, $port->refresh()->optical_rx_dbm);
        $this->assertSame(-1.0, $port->optical_tx_dbm);

        // An SNMP timeout on the optics read keeps the last values rather than wiping them.
        $snmp->throwOnWalk = true;
        app(PollDeviceMetrics::class)([$device->id]);
        $this->assertSame(-26.5, $port->refresh()->optical_rx_dbm);
    }

    public function test_the_interface_api_exposes_optical_power(): void
    {
        $this->actingAsUser();
        $device = $this->snmpMikrotik();
        NetworkInterface::factory()->for($device)->create(['name' => 'sfp1', 'optical_rx_dbm' => -7.5, 'optical_tx_dbm' => -2.0, 'optical_at' => now()]);

        $res = $this->getJson("/api/devices/{$device->id}/interfaces")->assertOk();

        $this->assertSame(-7.5, $res->json('data.0.optical_rx_dbm'));
        $this->assertSame(-2, (int) $res->json('data.0.optical_tx_dbm'));
    }

    // --- alerting --------------------------------------------------------------

    private function opticalPolicy(array $params = []): AlertPolicy
    {
        return AlertPolicy::factory()->create(['condition' => AlertCondition::OpticalPower, 'params' => $params]);
    }

    public function test_rx_below_threshold_fires_and_resolves_when_light_recovers(): void
    {
        $this->opticalPolicy(['optical' => 'rx', 'bound' => 'below', 'dbm' => -25]);
        $device = $this->snmpMikrotik();
        $low = NetworkInterface::factory()->for($device)->create(['name' => 'sfp1', 'optical_rx_dbm' => -27.4, 'optical_at' => now()]);
        NetworkInterface::factory()->for($device)->create(['name' => 'sfp2', 'optical_rx_dbm' => -8.0, 'optical_at' => now()]);

        app(EvaluateAlerts::class)();

        $events = AlertEvent::where('status', 'firing')->get();
        $this->assertCount(1, $events);
        $this->assertSame("device:{$device->id}:iface:{$low->id}:optical:rx", $events[0]->dedupe_key);
        $this->assertStringContainsString('-27.40 dBm on sfp1', $events[0]->message);

        $low->update(['optical_rx_dbm' => -10.0]);
        app(EvaluateAlerts::class)();
        $this->assertSame(0, AlertEvent::where('status', 'firing')->count());
    }

    public function test_defaults_to_rx_below_minus_25(): void
    {
        $this->opticalPolicy();
        $device = $this->snmpMikrotik();
        NetworkInterface::factory()->for($device)->create(['optical_rx_dbm' => -25.5, 'optical_tx_dbm' => -40.0, 'optical_at' => now()]);

        app(EvaluateAlerts::class)();

        $this->assertStringEndsWith(':optical:rx', AlertEvent::sole()->dedupe_key);
    }

    public function test_tx_above_threshold_fires(): void
    {
        $this->opticalPolicy(['optical' => 'tx', 'bound' => 'above', 'dbm' => 2]);
        $device = $this->snmpMikrotik();
        $hot = NetworkInterface::factory()->for($device)->create(['optical_tx_dbm' => 3.1, 'optical_at' => now()]);
        NetworkInterface::factory()->for($device)->create(['optical_tx_dbm' => -1.0, 'optical_at' => now()]);

        app(EvaluateAlerts::class)();

        $this->assertSame("device:{$device->id}:iface:{$hot->id}:optical:tx", AlertEvent::sole()->dedupe_key);
    }

    public function test_stale_readings_and_down_devices_never_fire(): void
    {
        $this->opticalPolicy(['dbm' => -25]);
        $stale = $this->snmpMikrotik();
        NetworkInterface::factory()->for($stale)->create(['optical_rx_dbm' => -30.0, 'optical_at' => now()->subHours(2)]);
        $down = $this->snmpMikrotik();
        $down->update(['status' => DeviceStatus::Down]);
        NetworkInterface::factory()->for($down)->create(['optical_rx_dbm' => -30.0, 'optical_at' => now()]);
        $copper = $this->snmpMikrotik();
        NetworkInterface::factory()->for($copper)->create(); // no optics at all

        app(EvaluateAlerts::class)();

        $this->assertSame(0, AlertEvent::count());
    }

    public function test_the_policy_scope_limits_which_devices_fire(): void
    {
        $in = $this->snmpMikrotik();
        $out = $this->snmpMikrotik();
        AlertPolicy::factory()->create([
            'condition' => AlertCondition::OpticalPower,
            'params' => ['dbm' => -25],
            'scope' => ['type' => 'devices', 'device_ids' => [$in->id]],
        ]);
        NetworkInterface::factory()->for($in)->create(['optical_rx_dbm' => -30.0, 'optical_at' => now()]);
        NetworkInterface::factory()->for($out)->create(['optical_rx_dbm' => -30.0, 'optical_at' => now()]);

        app(EvaluateAlerts::class)();

        $this->assertStringStartsWith("device:{$in->id}:", AlertEvent::sole()->dedupe_key);
    }

    public function test_the_policy_api_accepts_a_negative_dbm_and_rejects_nonsense(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/alert-policies', [
            'name' => 'Dirty fibre',
            'condition' => 'optical_power',
            'params' => ['optical' => 'rx', 'bound' => 'below', 'dbm' => -24.5],
        ])->assertCreated()->assertJsonPath('data.params.dbm', -24.5);

        $this->postJson('/api/alert-policies', [
            'name' => 'Bad',
            'condition' => 'optical_power',
            'params' => ['optical' => 'sideways', 'bound' => 'under', 'dbm' => -500],
        ])->assertJsonValidationErrors(['params.optical', 'params.bound', 'params.dbm']);
    }

    // --- remote agents ---------------------------------------------------------

    public function test_agent_jobs_ask_for_optical_on_the_metrics_cadence(): void
    {
        $agent = Agent::factory()->create();
        $snmp = $this->snmpMikrotik();
        $snmp->update(['agent_id' => $agent->id, 'monitored' => true]);
        $ros = $this->routerOsDevice();
        $ros->update(['agent_id' => $agent->id, 'monitored' => true]);

        $job = app(DispatchAgentJobs::class)->buildJob($agent->id);
        $s = collect($job['poll']['snmp'])->firstWhere('device_id', $snmp->id);
        $r = collect($job['poll']['routeros'])->firstWhere('device_id', $ros->id);

        $this->assertSame(['rx_walk' => self::RX, 'tx_walk' => self::TX, 'name_walk' => self::NAME, 'divisor' => 1000], $s['optical']);
        $this->assertTrue($r['optical']);

        // Straight away again: not due, so the agent isn't asked to re-read.
        $again = app(DispatchAgentJobs::class)->buildJob($agent->id);
        $this->assertNull(collect($again['poll']['snmp'])->firstWhere('device_id', $snmp->id)['optical']);
        $this->assertFalse(collect($again['poll']['routeros'])->firstWhere('device_id', $ros->id)['optical']);
    }

    public function test_ingests_agent_optical_reads_for_its_own_devices_only(): void
    {
        $agent = Agent::factory()->create();
        $mine = $this->snmpMikrotik();
        $mine->update(['agent_id' => $agent->id]);
        $sfp = NetworkInterface::factory()->for($mine)->create(['if_index' => 5, 'name' => 'sfp1']);
        $pulled = NetworkInterface::factory()->for($mine)->create(['if_index' => 6, 'name' => 'sfp2', 'optical_rx_dbm' => -4.0, 'optical_at' => now()]);
        $theirs = $this->snmpMikrotik();
        $theirPort = NetworkInterface::factory()->for($theirs)->create(['if_index' => 5, 'name' => 'sfp1']);

        app(IngestAgentResults::class)($agent, ['optical' => [
            ['device_id' => $mine->id, 'ports' => [['if_index' => 5, 'rx_dbm' => -6.5, 'tx_dbm' => null]]],
            ['device_id' => $theirs->id, 'ports' => [['name' => 'sfp1', 'rx_dbm' => -1.0, 'tx_dbm' => -1.0]]],
        ]]);

        $this->assertSame(-6.5, $sfp->refresh()->optical_rx_dbm);
        $this->assertNull($sfp->optical_tx_dbm);
        $this->assertNull($pulled->refresh()->optical_rx_dbm); // not in a successful read - cleared
        $this->assertNull($theirPort->refresh()->optical_rx_dbm); // another agent's device - ignored
    }

    public function test_an_older_agent_without_optical_leaves_values_alone(): void
    {
        $agent = Agent::factory()->create();
        $device = $this->snmpMikrotik();
        $device->update(['agent_id' => $agent->id]);
        $port = NetworkInterface::factory()->for($device)->create(['optical_rx_dbm' => -9.0, 'optical_at' => now()]);

        app(IngestAgentResults::class)($agent, ['pings' => [['device_id' => $device->id, 'up' => true]]]);

        $this->assertSame(-9.0, $port->refresh()->optical_rx_dbm);
    }
}
