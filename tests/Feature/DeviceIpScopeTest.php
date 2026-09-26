<?php

namespace Tests\Feature;

use App\Actions\Agent\IngestAgentScan;
use App\Enums\PollMethod;
use App\Models\Agent;
use App\Models\Credential;
use App\Models\Device;
use App\Models\DiscoveryCandidate;
use App\Models\Subnet;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A management IP is unique per poll scope - one agent, or the central server - not across the
 * whole install (GitHub #49). Two sites behind two agents can reuse the same private subnet.
 */
class DeviceIpScopeTest extends TestCase
{
    use RefreshDatabase;

    private function addDevice(array $body)
    {
        return $this->postJson('/api/devices', $body + ['name' => 'dev', 'poll_method' => PollMethod::None->value]);
    }

    public function test_the_same_ip_can_exist_behind_two_different_agents_and_centrally(): void
    {
        $this->actingAsUser();
        [$a, $b] = Agent::factory()->count(2)->create();

        $this->addDevice(['name' => 'site-a', 'mgmt_ip' => '192.168.1.10', 'agent_id' => $a->id])->assertCreated();
        $this->addDevice(['name' => 'site-b', 'mgmt_ip' => '192.168.1.10', 'agent_id' => $b->id])->assertCreated();
        $this->addDevice(['name' => 'hq', 'mgmt_ip' => '192.168.1.10'])->assertCreated();

        $this->assertSame(3, Device::where('mgmt_ip', '192.168.1.10')->count());
    }

    public function test_the_same_ip_twice_in_one_scope_is_rejected_with_a_readable_error(): void
    {
        $this->actingAsUser();
        $agent = Agent::factory()->create(['name' => 'Site A']);
        Device::factory()->create(['name' => 'core', 'mgmt_ip' => '192.168.1.10', 'agent_id' => $agent->id]);
        Device::factory()->create(['name' => 'hq', 'mgmt_ip' => '10.0.0.1']);

        $this->addDevice(['mgmt_ip' => '192.168.1.10', 'agent_id' => $agent->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('mgmt_ip')
            ->assertJsonPath('errors.mgmt_ip.0', fn ($m) => str_contains($m, '"core"') && str_contains($m, 'Site A'));

        $this->addDevice(['mgmt_ip' => '10.0.0.1'])
            ->assertStatus(422)
            ->assertJsonPath('errors.mgmt_ip.0', fn ($m) => str_contains($m, 'central server'));
    }

    public function test_moving_a_device_onto_an_agent_that_already_polls_its_ip_is_rejected(): void
    {
        $this->actingAsUser();
        $agent = Agent::factory()->create();
        Device::factory()->create(['mgmt_ip' => '192.168.1.10', 'agent_id' => $agent->id]);
        $central = Device::factory()->create(['mgmt_ip' => '192.168.1.10']);

        // Only agent_id changes - the effective (ip, agent) pair still has to be checked.
        $this->putJson("/api/devices/{$central->id}", ['agent_id' => $agent->id])
            ->assertStatus(422)->assertJsonValidationErrors('mgmt_ip');

        // Re-saving a device unchanged is not a clash with itself.
        $this->putJson("/api/devices/{$central->id}", ['name' => 'renamed'])->assertOk();
    }

    public function test_the_database_enforces_the_scope_even_past_validation(): void
    {
        $agent = Agent::factory()->create();
        Device::factory()->create(['mgmt_ip' => '192.168.1.10', 'agent_id' => $agent->id]);

        $this->expectException(QueryException::class);
        Device::factory()->create(['mgmt_ip' => '192.168.1.10', 'agent_id' => $agent->id]);
    }

    public function test_deleting_an_agent_is_refused_when_its_devices_would_clash_centrally(): void
    {
        $this->actingAsUser();
        $agent = Agent::factory()->create();
        Device::factory()->create(['name' => 'remote', 'mgmt_ip' => '192.168.1.10', 'agent_id' => $agent->id]);
        Device::factory()->create(['mgmt_ip' => '192.168.1.10']);

        $this->deleteJson("/api/agents/{$agent->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'remote (192.168.1.10)'));
        $this->assertModelExists($agent);

        // With no clash, it deletes and its devices revert to central polling.
        $clean = Agent::factory()->create();
        $moved = Device::factory()->create(['mgmt_ip' => '192.168.9.9', 'agent_id' => $clean->id]);
        $this->deleteJson("/api/agents/{$clean->id}")->assertNoContent();
        $this->assertNull($moved->fresh()->agent_id);
    }

    public function test_an_agent_scan_records_the_agent_and_ignores_same_ip_devices_in_other_scopes(): void
    {
        $agent = Agent::factory()->create();
        $cred = Credential::factory()->create();
        $subnet = Subnet::factory()->create(['agent_id' => $agent->id, 'cidr' => '192.168.1.0/24']);
        // A central box on the same IP is a different device on another network.
        Device::factory()->create(['mgmt_ip' => '192.168.1.10']);

        app(IngestAgentScan::class)($agent, ['subnets' => [[
            'subnet_id' => $subnet->id,
            'candidates' => [['ip' => '192.168.1.10', 'sysname' => 'sw', 'method' => 'snmp', 'credential_id' => $cred->id]],
        ]]]);

        $this->assertDatabaseHas('discovery_candidates', ['ip' => '192.168.1.10', 'agent_id' => $agent->id]);
    }

    public function test_promoting_an_agent_found_candidate_makes_a_device_polled_by_that_agent(): void
    {
        $this->actingAsUser();
        $agent = Agent::factory()->create();
        Device::factory()->create(['mgmt_ip' => '192.168.1.10']); // same IP centrally - must not be reused
        $candidate = DiscoveryCandidate::factory()->create([
            'ip' => '192.168.1.10', 'agent_id' => $agent->id, 'detected_method' => PollMethod::Snmp,
        ]);

        $this->postJson("/api/discovery-candidates/{$candidate->id}/approve")->assertSuccessful();

        $this->assertDatabaseHas('devices', ['mgmt_ip' => '192.168.1.10', 'agent_id' => $agent->id]);
        $this->assertSame(2, Device::where('mgmt_ip', '192.168.1.10')->count());
    }
}
