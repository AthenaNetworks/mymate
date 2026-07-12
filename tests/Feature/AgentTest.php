<?php

namespace Tests\Feature;

use App\Actions\Polling\PingFleet;
use App\Enums\PollMethod;
use App\Models\Agent;
use App\Models\Device;
use App\Models\Subnet;
use App\Models\User;
use App\Services\Polling\PollDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Remote agents: enrolment/token, agent management API, and - the
 * load-bearing bit - that central polling/discovery SKIPS agent-assigned work (the agent
 * handles those on its own network; the app must not double-poll or fail trying to reach them).
 */
class AgentTest extends TestCase
{
    use RefreshDatabase;

    // --- Enrolment / token ------------------------------------------------

    public function test_enrol_returns_a_token_and_stores_only_its_hash(): void
    {
        [$agent, $token] = Agent::enrol('Site A');

        $this->assertStringStartsWith('mma_', $token);
        $this->assertDatabaseHas('agents', ['id' => $agent->id, 'name' => 'Site A', 'status' => 'enrolled']);
        // Only the hash is stored - never the plaintext.
        $this->assertSame(Agent::hashToken($token), $agent->fresh()->token_hash);
        $this->assertDatabaseMissing('agents', ['token_hash' => $token]);
    }

    public function test_from_token_resolves_the_agent(): void
    {
        [$agent, $token] = Agent::enrol('Site A');

        $this->assertTrue(Agent::fromToken($token)?->is($agent));
        $this->assertNull(Agent::fromToken('mma_wrong'));
        $this->assertNull(Agent::fromToken(null));
    }

    public function test_token_hash_is_never_serialised(): void
    {
        [$agent] = Agent::enrol('Site A');
        $this->assertArrayNotHasKey('token_hash', $agent->toArray());
    }

    public function test_create_command_enrols_and_prints_a_token(): void
    {
        $this->artisan('mymate:agent:create', ['name' => 'Site B'])
            ->expectsOutputToContain('mma_')
            ->assertOk();
        $this->assertDatabaseHas('agents', ['name' => 'Site B']);
    }

    // --- Dispatch partitioning (the core behaviour) -----------------------

    public function test_ping_sweep_skips_agent_assigned_devices(): void
    {
        $agent = Agent::factory()->create();
        $local = Device::factory()->create(['monitored' => true, 'agent_id' => null]);
        $remote = Device::factory()->create(['monitored' => true, 'agent_id' => $agent->id]);

        // Capture which IPs the pinger is asked to probe.
        $seen = [];
        $this->mock(\App\Services\Ping\Pinger::class, function ($m) use (&$seen) {
            $m->shouldReceive('reachable')->andReturnUsing(function (array $ips) use (&$seen) {
                $seen = $ips;

                return [];
            });
        });

        app(PingFleet::class)();

        $this->assertContains($local->mgmt_ip, $seen);
        $this->assertNotContains($remote->mgmt_ip, $seen, 'agent device must not be pinged centrally');
    }

    public function test_throughput_dispatch_skips_agent_assigned_devices(): void
    {
        $agent = Agent::factory()->create();
        Device::factory()->create(['monitored' => true, 'poll_method' => PollMethod::Snmp, 'agent_id' => null]);
        Device::factory()->create(['monitored' => true, 'poll_method' => PollMethod::Snmp, 'agent_id' => $agent->id]);

        // The dispatcher shards device IDs into batch jobs; assert only the local one is queued.
        \Illuminate\Support\Facades\Bus::fake();
        app(PollDispatcher::class)->dispatch();

        \Illuminate\Support\Facades\Bus::assertDispatched(
            \App\Jobs\PollInterfacesBatchJob::class,
            function ($job) {
                // The batch carries only local device ids - exactly one device is local.
                return count($job->deviceIds ?? $job->ids ?? []) === 1;
            }
        );
    }

    public function test_deleting_an_agent_reverts_its_devices_and_subnets_to_central(): void
    {
        $agent = Agent::factory()->create();
        $device = Device::factory()->create(['agent_id' => $agent->id]);
        $subnet = Subnet::factory()->create(['agent_id' => $agent->id]);

        $agent->delete();

        $this->assertNull($device->fresh()->agent_id);
        $this->assertNull($subnet->fresh()->agent_id);
    }

    // --- Management API ---------------------------------------------------

    public function test_operator_can_list_agents_without_the_token(): void
    {
        $this->actingAsUser();
        Agent::factory()->create(['name' => 'Site A']);

        $this->getJson('/api/agents')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Site A'])
            ->assertJsonMissing(['token_hash' => true]);
    }

    public function test_admin_can_enrol_an_agent_and_gets_the_token_once(): void
    {
        $this->actingAsUser(); // admin

        $res = $this->postJson('/api/agents', ['name' => 'Site A'])->assertCreated();
        $this->assertStringStartsWith('mma_', $res->json('token'));
        $this->assertSame('Site A', $res->json('agent.name'));
    }

    public function test_non_admin_cannot_enrol_or_delete(): void
    {
        $this->actingAs(User::factory()->create()); // viewer
        $this->postJson('/api/agents', ['name' => 'X'])->assertForbidden();

        $agent = Agent::factory()->create();
        $this->deleteJson("/api/agents/{$agent->id}")->assertForbidden();
    }

    public function test_admin_can_delete_an_agent(): void
    {
        $this->actingAsUser();
        $agent = Agent::factory()->create();

        $this->deleteJson("/api/agents/{$agent->id}")->assertNoContent();
        $this->assertDatabaseMissing('agents', ['id' => $agent->id]);
    }

    public function test_device_can_be_assigned_to_an_agent_via_the_api(): void
    {
        $this->actingAsUser();
        $agent = Agent::factory()->create();

        $res = $this->postJson('/api/devices', [
            'name' => 'r1', 'mgmt_ip' => '10.0.0.1', 'poll_method' => PollMethod::Snmp->value, 'agent_id' => $agent->id,
        ])->assertCreated();

        $this->assertSame($agent->id, $res->json('data.agent_id'));
    }
}
