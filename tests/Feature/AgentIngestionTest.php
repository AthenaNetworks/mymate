<?php

namespace Tests\Feature;

use App\Actions\Agent\DispatchAgentJobs;
use App\Actions\Agent\IngestAgentResults;
use App\Actions\Agent\IngestAgentScan;
use App\Enums\AgentStatus;
use App\Enums\DeviceStatus;
use App\Enums\PollMethod;
use App\Events\DeviceStatusChanged;
use App\Events\InterfaceUtilUpdated;
use App\Models\Agent;
use App\Models\Device;
use App\Models\NetworkInterface;
use App\Models\Outage;
use App\Models\Subnet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * The agent hub's domain layer: ingesting an agent's results into the same pipeline central
 * polling uses (status/outages/util/broadcast), and building the work published to an agent.
 */
class AgentIngestionTest extends TestCase
{
    use RefreshDatabase;

    public function test_ingest_applies_up_down_and_records_an_outage(): void
    {
        Event::fake([DeviceStatusChanged::class]);
        $agent = Agent::factory()->create();
        $device = Device::factory()->create(['agent_id' => $agent->id, 'status' => DeviceStatus::Up]);

        app(IngestAgentResults::class)($agent, ['pings' => [['device_id' => $device->id, 'up' => false]]]);

        $this->assertSame(DeviceStatus::Down, $device->fresh()->status);
        $this->assertTrue(Outage::where('device_id', $device->id)->whereNull('ended_at')->exists());
        Event::assertDispatched(DeviceStatusChanged::class);
    }

    public function test_ingest_ignores_devices_not_owned_by_the_agent(): void
    {
        $agent = Agent::factory()->create();
        $other = Device::factory()->create(['agent_id' => null, 'status' => DeviceStatus::Up]); // central device

        app(IngestAgentResults::class)($agent, ['pings' => [['device_id' => $other->id, 'up' => false]]]);

        // A compromised/buggy agent can't move a device that isn't assigned to it.
        $this->assertSame(DeviceStatus::Up, $other->fresh()->status);
    }

    public function test_ingest_writes_throughput_util_and_broadcasts(): void
    {
        Event::fake([InterfaceUtilUpdated::class]);
        $agent = Agent::factory()->create();
        $device = Device::factory()->create(['agent_id' => $agent->id]);
        $iface = NetworkInterface::factory()->create(['device_id' => $device->id, 'speed_mbps' => 1000]);

        // 100 Mbps in on a 1 Gbps link -> 10% util.
        app(IngestAgentResults::class)($agent, [
            'throughput' => [['interface_id' => $iface->id, 'in_bps' => 100_000_000, 'out_bps' => 0]],
        ]);

        $iface->refresh();
        $this->assertSame(100_000_000, (int) $iface->bps_in);
        $this->assertEqualsWithDelta(10.0, $iface->util_in, 0.01);
        $this->assertDatabaseHas('interface_samples', ['interface_id' => $iface->id, 'bps_in' => 100_000_000]);
        Event::assertDispatched(InterfaceUtilUpdated::class);
    }

    public function test_ingest_ignores_interfaces_not_owned_by_the_agent(): void
    {
        Event::fake([InterfaceUtilUpdated::class]);
        $agent = Agent::factory()->create();
        $central = Device::factory()->create(['agent_id' => null]);
        $iface = NetworkInterface::factory()->create(['device_id' => $central->id, 'speed_mbps' => 1000, 'bps_in' => 5]);

        app(IngestAgentResults::class)($agent, [
            'throughput' => [['interface_id' => $iface->id, 'in_bps' => 999_999_999, 'out_bps' => 0]],
        ]);

        $this->assertSame(5, (int) $iface->fresh()->bps_in); // unchanged
        Event::assertNotDispatched(InterfaceUtilUpdated::class);
    }

    public function test_build_job_carries_ping_snmp_with_creds_and_subnets(): void
    {
        $agent = Agent::factory()->create();
        $cred = \App\Models\Credential::create(['name' => 'c', 'type' => 'snmp', 'snmp_community' => 'public']);
        $device = Device::factory()->create([
            'agent_id' => $agent->id, 'poll_method' => PollMethod::Snmp, 'credential_id' => $cred->id, 'monitored' => true,
        ]);
        NetworkInterface::factory()->create(['device_id' => $device->id, 'if_index' => 3]);
        Subnet::factory()->create(['agent_id' => $agent->id, 'cidr' => '10.9.0.0/24', 'enabled' => true]);
        // Not this agent's - must be excluded.
        Device::factory()->create(['agent_id' => null, 'poll_method' => PollMethod::Snmp]);

        $job = app(DispatchAgentJobs::class)->buildJob($agent->id);

        $this->assertSame($agent->id, $job['agent_id']);
        // Ping: every assigned device.
        $this->assertCount(1, $job['poll']['ping']);
        $this->assertSame($device->id, $job['poll']['ping'][0]['device_id']);
        // SNMP: with decrypted community + interfaces.
        $this->assertCount(1, $job['poll']['snmp']);
        $this->assertSame('public', $job['poll']['snmp'][0]['community']);
        $this->assertSame(3, $job['poll']['snmp'][0]['interfaces'][0]['if_index']);
        $this->assertSame('10.9.0.0/24', $job['scan']['subnets'][0]['cidr']);
    }

    public function test_build_job_carries_routeros_targets_with_login_and_interface_names(): void
    {
        $agent = Agent::factory()->create();
        $cred = \App\Models\Credential::create(['name' => 'ros', 'type' => 'routeros', 'username' => 'admin', 'password' => 'pw', 'api_port' => 8729]);
        $device = Device::factory()->create([
            'agent_id' => $agent->id, 'poll_method' => PollMethod::RouterOs, 'credential_id' => $cred->id, 'monitored' => true,
        ]);
        NetworkInterface::factory()->create(['device_id' => $device->id, 'name' => 'ether1']);

        $job = app(DispatchAgentJobs::class)->buildJob($agent->id);

        $this->assertCount(1, $job['poll']['ping']);        // pinged like everything
        $this->assertSame([], $job['poll']['snmp']);        // not an SNMP device
        $this->assertCount(1, $job['poll']['routeros']);
        $this->assertSame('admin', $job['poll']['routeros'][0]['username']);
        $this->assertSame('pw', $job['poll']['routeros'][0]['password']);
        $this->assertSame(8729, $job['poll']['routeros'][0]['api_port']);
        $this->assertSame('ether1', $job['poll']['routeros'][0]['interfaces'][0]['name']);
    }

    public function test_build_job_scan_carries_due_subnets_and_the_credential_pool(): void
    {
        $agent = Agent::factory()->create();
        \App\Models\Credential::create(['name' => 's', 'type' => 'snmp', 'snmp_community' => 'public']);
        \App\Models\Credential::create(['name' => 'r', 'type' => 'routeros', 'username' => 'admin', 'password' => 'pw', 'api_port' => 8729]);
        // Due: never scanned. Not due: scanned a moment ago with an hour cadence.
        Subnet::factory()->create(['agent_id' => $agent->id, 'cidr' => '10.9.0.0/24', 'enabled' => true, 'last_scanned_at' => null]);
        Subnet::factory()->create(['agent_id' => $agent->id, 'cidr' => '10.8.0.0/24', 'enabled' => true, 'scan_interval_s' => 3600, 'last_scanned_at' => now()]);

        $job = app(DispatchAgentJobs::class)->buildJob($agent->id);

        $this->assertCount(1, $job['scan']['subnets']); // only the due one
        $this->assertSame('10.9.0.0/24', $job['scan']['subnets'][0]['cidr']);
        // Credential pool travels for the agent to probe with.
        $this->assertSame('public', $job['scan']['credentials']['snmp'][0]['community']);
        $this->assertSame('admin', $job['scan']['credentials']['routeros'][0]['username']);
        $this->assertSame('pw', $job['scan']['credentials']['routeros'][0]['password']);
    }

    public function test_build_job_omits_credential_pool_when_no_subnets_are_due(): void
    {
        $agent = Agent::factory()->create();
        \App\Models\Credential::create(['name' => 's', 'type' => 'snmp', 'snmp_community' => 'public']);
        Device::factory()->create(['agent_id' => $agent->id, 'monitored' => true]); // gives it ping work

        $job = app(DispatchAgentJobs::class)->buildJob($agent->id);

        $this->assertSame([], $job['scan']['subnets']);
        $this->assertSame([], $job['scan']['credentials']['snmp']); // pool not sent when nothing to scan
    }

    public function test_ingest_scan_queues_new_identified_candidates_and_stamps_the_subnet(): void
    {
        $agent = Agent::factory()->create();
        $subnet = Subnet::factory()->create(['agent_id' => $agent->id, 'cidr' => '10.9.0.0/24', 'last_scanned_at' => null]);
        $cred = \App\Models\Credential::create(['name' => 's', 'type' => 'snmp', 'snmp_community' => 'public']);

        app(IngestAgentScan::class)($agent, ['subnets' => [[
            'subnet_id' => $subnet->id,
            'candidates' => [
                ['ip' => '10.9.0.5', 'sysname' => 'sw1', 'method' => 'snmp', 'credential_id' => $cred->id],
                ['ip' => '10.9.0.6'], // ping-only responder, unidentified - not queued
            ],
        ]]]);

        $this->assertDatabaseHas('discovery_candidates', [
            'ip' => '10.9.0.5', 'sysname' => 'sw1', 'detected_method' => 'snmp', 'status' => 'new',
        ]);
        $this->assertDatabaseMissing('discovery_candidates', ['ip' => '10.9.0.6']);
        $this->assertNotNull($subnet->fresh()->last_scanned_at);
    }

    public function test_ingest_scan_skips_ips_that_are_already_devices_and_bumps_existing(): void
    {
        $agent = Agent::factory()->create();
        $subnet = Subnet::factory()->create(['agent_id' => $agent->id, 'cidr' => '10.9.0.0/24']);
        Device::factory()->create(['mgmt_ip' => '10.9.0.5']);
        $existing = \App\Models\DiscoveryCandidate::create([
            'ip' => '10.9.0.6', 'status' => 'ignored', 'detected_method' => 'snmp',
            'first_seen' => now()->subDay(), 'last_seen' => now()->subDay(),
        ]);

        app(IngestAgentScan::class)($agent, ['subnets' => [[
            'subnet_id' => $subnet->id,
            'candidates' => [
                ['ip' => '10.9.0.5', 'sysname' => 'dev', 'method' => 'snmp', 'credential_id' => 1], // already a device
                ['ip' => '10.9.0.6', 'sysname' => 'x', 'method' => 'snmp', 'credential_id' => 1],    // already queued
            ],
        ]]]);

        // The device IP never becomes a candidate.
        $this->assertDatabaseMissing('discovery_candidates', ['ip' => '10.9.0.5']);
        // The existing (ignored) candidate stays ignored but its last_seen advances.
        $existing->refresh();
        $this->assertSame('ignored', $existing->status->value);
        $this->assertTrue($existing->last_seen->greaterThan(now()->subHour()));
    }

    public function test_ingest_scan_ignores_subnets_not_owned_by_the_agent(): void
    {
        $agent = Agent::factory()->create();
        $other = Subnet::factory()->create(['agent_id' => null, 'cidr' => '10.9.0.0/24', 'last_scanned_at' => null]);

        app(IngestAgentScan::class)($agent, ['subnets' => [[
            'subnet_id' => $other->id,
            'candidates' => [['ip' => '10.9.0.5', 'method' => 'snmp', 'credential_id' => 1]],
        ]]]);

        $this->assertDatabaseMissing('discovery_candidates', ['ip' => '10.9.0.5']);
        $this->assertNull($other->fresh()->last_scanned_at); // not restamped by a foreign agent
    }

    public function test_dispatch_only_targets_online_agents(): void
    {
        $online = Agent::factory()->create(['status' => AgentStatus::Online]);
        $offline = Agent::factory()->create(['status' => AgentStatus::Offline]);
        Device::factory()->create(['agent_id' => $online->id, 'monitored' => true]);
        Device::factory()->create(['agent_id' => $offline->id, 'monitored' => true]);

        \Illuminate\Support\Facades\Redis::shouldReceive('publish')->once()
            ->with(DispatchAgentJobs::CHANNEL, \Mockery::type('string'));

        $count = app(DispatchAgentJobs::class)();
        $this->assertSame(1, $count); // only the online agent
    }

    public function test_ingest_discovery_creates_interfaces_and_applies_snmp_facts(): void
    {
        $agent = Agent::factory()->create();
        $device = Device::factory()->create(['agent_id' => $agent->id]);

        app(IngestAgentResults::class)($agent, ['discovery' => [[
            'device_id' => $device->id,
            'interfaces' => [
                ['if_index' => 1, 'name' => 'ether1', 'descr' => 'Ethernet', 'speed_mbps' => 1000, 'oper_up' => true],
                ['if_index' => 2, 'name' => 'ether2', 'oper_up' => false],
            ],
            'facts' => ['sys_descr' => 'Linux host 6.1.0', 'sys_location' => '[-31.95, 115.86]', 'ent_models' => [], 'ent_serials' => []],
        ]]]);

        $this->assertDatabaseHas('interfaces', ['device_id' => $device->id, 'if_index' => 1, 'name' => 'ether1', 'speed_mbps' => 1000, 'oper_status' => 'up']);
        $this->assertDatabaseHas('interfaces', ['device_id' => $device->id, 'if_index' => 2, 'oper_status' => 'down']);

        $device->refresh();
        $this->assertSame('Linux', $device->vendor);
        $this->assertEqualsWithDelta(-31.95, (float) $device->latitude, 0.001);
        $this->assertSame('snmp', $device->geo_source);
    }

    public function test_ingest_discovery_applies_routeros_facts(): void
    {
        $agent = Agent::factory()->create();
        $device = Device::factory()->create(['agent_id' => $agent->id, 'poll_method' => PollMethod::RouterOs]);

        app(IngestAgentResults::class)($agent, ['discovery' => [[
            'device_id' => $device->id,
            'interfaces' => [['if_index' => 1, 'name' => 'ether1', 'oper_up' => true]],
            'routeros_facts' => ['version' => '7.20.8 (stable)', 'model' => 'RB5009', 'serial' => 'ABC123', 'location' => '[-32.1, 116.0]'],
        ]]]);

        $device->refresh();
        $this->assertSame('MikroTik', $device->vendor);
        $this->assertSame('RB5009', $device->model);
        $this->assertSame('7.20.8', $device->os_version);
        $this->assertEqualsWithDelta(-32.1, (float) $device->latitude, 0.001);
    }

    public function test_ingest_discovery_ignores_a_device_not_owned_by_the_agent(): void
    {
        $agent = Agent::factory()->create();
        $central = Device::factory()->create(['agent_id' => null]);

        app(IngestAgentResults::class)($agent, ['discovery' => [[
            'device_id' => $central->id,
            'interfaces' => [['if_index' => 1, 'name' => 'ether1', 'oper_up' => true]],
            'facts' => ['sys_descr' => 'Linux', 'sys_location' => '', 'ent_models' => [], 'ent_serials' => []],
        ]]]);

        $this->assertDatabaseMissing('interfaces', ['device_id' => $central->id]);
    }

    public function test_ingest_applies_a_probe_verdict_with_dampening(): void
    {
        $agent = Agent::factory()->create();
        $device = Device::factory()->create(['agent_id' => $agent->id]);
        $probe = \App\Models\Probe::factory()->create(['device_id' => $device->id, 'fail_threshold' => 1]);

        app(IngestAgentResults::class)($agent, ['probes' => [[
            'probe_id' => $probe->id, 'up' => true, 'latency_ms' => 12.3, 'message' => 'HTTP 200',
        ]]]);

        $probe->refresh();
        $this->assertSame(DeviceStatus::Up, $probe->status);
        $this->assertEqualsWithDelta(12.3, (float) $probe->latency_ms, 0.01);
        $this->assertSame('HTTP 200', $probe->message);
        $this->assertDatabaseHas('probe_samples', ['probe_id' => $probe->id, 'up' => true]);
    }
}
