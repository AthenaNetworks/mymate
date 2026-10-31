<?php

namespace Tests\Feature;

use App\Actions\Agent\DispatchAgentJobs;
use App\Actions\Agent\IngestAgentResults;
use App\Actions\Agent\TestAgentSnmp;
use App\Enums\AgentStatus;
use App\Enums\PollMethod;
use App\Models\Agent;
use App\Models\Credential;
use App\Models\Device;
use App\Models\Sensor;
use App\Services\Snmp\SnmpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/** GitHub #51: custom SNMP sensors and "Test OID" for devices behind a remote agent. */
class AgentSensorsTest extends TestCase
{
    use RefreshDatabase;

    private function agentDevice(?Agent $agent = null): Device
    {
        $agent ??= Agent::factory()->create(['status' => AgentStatus::Online, 'last_seen_at' => now()]);

        return Device::factory()->create([
            'agent_id' => $agent->id,
            'monitored' => true,
            'poll_method' => PollMethod::Snmp,
            'credential_id' => Credential::factory()->create(['snmp_community' => 'public'])->id,
        ]);
    }

    public function test_agent_job_carries_in_scope_sensors_when_due(): void
    {
        $device = $this->agentDevice();
        $other = Device::factory()->create();
        $all = Sensor::factory()->create(['oid' => '1.3.6.1.4.1.1.1', 'mode' => 'get']);
        Sensor::factory()->create(['oid' => '1.3.6.1.4.1.1.2', 'scope' => ['type' => 'devices', 'device_ids' => [$other->id]]]);
        Sensor::factory()->create(['oid' => '1.3.6.1.4.1.1.3', 'enabled' => false]);

        $job = app(DispatchAgentJobs::class)->buildJob($device->agent_id);

        $this->assertSame([['sensor_id' => $all->id, 'oid' => '1.3.6.1.4.1.1.1', 'mode' => 'get']], $job['poll']['snmp'][0]['sensors']);

        // Not due again until the metrics interval passes.
        $again = app(DispatchAgentJobs::class)->buildJob($device->agent_id);
        $this->assertSame([], $again['poll']['snmp'][0]['sensors']);
    }

    public function test_ingest_stores_agent_sensor_values_like_a_central_read(): void
    {
        $device = $this->agentDevice();
        $agent = Agent::find($device->agent_id);
        $get = Sensor::factory()->create(['mode' => 'get', 'divisor' => 10]);
        $walk = Sensor::factory()->create(['mode' => 'walk', 'agg' => 'max', 'divisor' => 1]);
        $off = Sensor::factory()->create(['enabled' => false]);
        $foreign = Device::factory()->create(); // not this agent's device

        app(IngestAgentResults::class)($agent, ['sensors' => [
            ['device_id' => $device->id, 'sensor_id' => $get->id, 'values' => ['235']],
            ['device_id' => $device->id, 'sensor_id' => $walk->id, 'values' => ['3', '9', 'n/a', '4']],
            ['device_id' => $device->id, 'sensor_id' => $off->id, 'values' => ['1']],
            ['device_id' => $foreign->id, 'sensor_id' => $get->id, 'values' => ['1']],
        ]]);

        $readings = DB::table('sensor_readings')->get()->keyBy('sensor_id');
        $this->assertCount(2, $readings);
        $this->assertEqualsWithDelta(23.5, $readings[$get->id]->value, 0.001);
        $this->assertEqualsWithDelta(9.0, $readings[$walk->id]->value, 0.001);
        $this->assertSame(2, DB::table('sensor_samples')->count());
    }

    public function test_test_oid_runs_through_the_agent(): void
    {
        $this->actingAsUser();
        $device = $this->agentDevice();
        // The central SNMP client must not be used for an agent device.
        $this->mock(SnmpClient::class)->shouldNotReceive('get', 'walk');

        // Stand in for the hub + agent: answer the published request straight away.
        Redis::shouldReceive('publish')->once()->andReturnUsing(function (string $channel, string $json) use ($device): int {
            $msg = json_decode($json, true);
            $this->assertSame($device->agent_id, $msg['agent_id']);
            $this->assertSame($device->mgmt_ip, $msg['snmp_test']['ip']);
            TestAgentSnmp::recordReply($device->agent_id, ['request_id' => $msg['snmp_test']['request_id'], 'values' => ['2200']]);

            return 1;
        });

        $res = $this->postJson('/api/sensors/test', [
            'device_id' => $device->id, 'oid' => '1.3.6.1.4.1.9.9.13.1.3.1.3.1', 'mode' => 'get', 'divisor' => 100,
        ])->assertOk()->assertJsonPath('data.ok', true);

        $this->assertEqualsWithDelta(22.0, $res->json('data.value'), 0.001);
    }

    public function test_test_oid_reports_an_offline_agent(): void
    {
        $this->actingAsUser();
        $agent = Agent::factory()->create(['status' => AgentStatus::Offline]);
        $device = $this->agentDevice($agent);
        Redis::shouldReceive('publish')->never();

        $this->postJson('/api/sensors/test', ['device_id' => $device->id, 'oid' => '1.3.6.1'])
            ->assertOk()->assertJsonPath('data.ok', false)
            ->assertJsonPath('data.error', fn (string $e): bool => str_contains($e, 'offline'));
    }

    public function test_a_reply_from_another_agent_is_ignored(): void
    {
        Cache::put('agent:snmp-test:abc:agent', 5, 60);

        TestAgentSnmp::recordReply(6, ['request_id' => 'abc', 'values' => ['1']]);
        $this->assertNull(Cache::get('agent:snmp-test:abc'));

        TestAgentSnmp::recordReply(5, ['request_id' => 'abc', 'error' => 'timeout']);
        $this->assertSame(['error' => 'timeout'], Cache::get('agent:snmp-test:abc'));
    }
}
