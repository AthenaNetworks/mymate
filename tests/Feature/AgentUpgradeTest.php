<?php

namespace Tests\Feature;

use App\Enums\AgentStatus;
use App\Jobs\PrepareAgentUpgradeJob;
use App\Models\Agent;
use App\Models\User;
use App\Services\Agent\AgentReleases;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Remote agent upgrades. The server's part: only offer it to agents that say they can
 * (agent/COMPATIBILITY.md), fetch the matching build and check it against the release checksums,
 * and send the agent a verified size + SHA-256. The agent's side (download check, supervised
 * start, rollback) is tested in agent/internal/upgrade.
 */
class AgentUpgradeTest extends TestCase
{
    use RefreshDatabase;

    private string $releases;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mymate.update.version' => '1.10.0']);
        // A throwaway storage path: never the real release cache.
        $this->releases = sys_get_temp_dir().'/agent-upgrade-test-'.uniqid();
        $this->app->useStoragePath($this->releases);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->releases);
        parent::tearDown();
    }


    private function agent(array $attrs = []): Agent
    {
        return Agent::factory()->create($attrs + [
            'status' => AgentStatus::Online, 'last_seen_at' => now(),
            'version' => 'v1.9.0', 'platform' => 'linux/amd64', 'capabilities' => ['upgrade'],
        ]);
    }

    public function test_an_admin_queues_an_upgrade_to_the_servers_version(): void
    {
        Queue::fake();
        $this->actingAsUser();
        $agent = $this->agent();

        $this->getJson('/api/agents')->assertOk()
            ->assertJsonPath('data.0.can_upgrade', true)
            ->assertJsonPath('data.0.upgrade_target', 'v1.10.0');

        $this->postJson("/api/agents/{$agent->id}/upgrade")->assertAccepted()
            ->assertJsonPath('data.upgrade.status', 'queued')
            ->assertJsonPath('data.upgrade.version', 'v1.10.0');

        Queue::assertPushed(PrepareAgentUpgradeJob::class, fn ($j) => $j->agentId === $agent->id && $j->version === 'v1.10.0');
    }

    public function test_older_agents_and_ones_that_cant_are_never_sent_an_upgrade(): void
    {
        Queue::fake();
        $this->actingAsUser();

        // An agent from before remote upgrades reports no capabilities at all.
        $old = $this->agent(['capabilities' => null]);
        $this->postJson("/api/agents/{$old->id}/upgrade")->assertUnprocessable()
            ->assertJsonPath('errors.agent.0', fn (string $m) => str_contains($m, "can't upgrade itself"));

        $offline = $this->agent(['status' => AgentStatus::Offline]);
        $this->postJson("/api/agents/{$offline->id}/upgrade")->assertUnprocessable();

        $current = $this->agent(['version' => 'v1.10.0']);
        $this->postJson("/api/agents/{$current->id}/upgrade")->assertUnprocessable();

        $odd = $this->agent(['platform' => 'plan9/386']);
        $this->postJson("/api/agents/{$odd->id}/upgrade")->assertUnprocessable();

        Queue::assertNothingPushed();
    }

    public function test_only_admins_can_upgrade(): void
    {
        Queue::fake();
        $agent = $this->agent();
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        $this->postJson("/api/agents/{$agent->id}/upgrade")->assertForbidden();
        Queue::assertNothingPushed();
    }

    /** A real release-style tar.gz holding a binary, and its SHA256SUMS line. */
    private function fakeRelease(string $binary): array
    {
        $dir = sys_get_temp_dir().'/agent-rel-'.uniqid();
        $name = 'mymate-agent_v1.10.0_linux_amd64';
        File::ensureDirectoryExists("{$dir}/{$name}");
        file_put_contents("{$dir}/{$name}/mymate-agent", $binary);
        (new Process(['tar', '-czf', "{$dir}/{$name}.tar.gz", '-C', $dir, $name]))->mustRun();
        $archive = (string) file_get_contents("{$dir}/{$name}.tar.gz");
        File::deleteDirectory($dir);

        return [$archive, hash('sha256', $archive)."  ./{$name}.tar.gz\n"];
    }

    public function test_the_job_fetches_and_verifies_the_build_then_sends_it(): void
    {
        $binary = str_repeat('agent-bytes', 100);
        [$archive, $sums] = $this->fakeRelease($binary);
        Http::fake([
            '*/v1.10.0/SHA256SUMS' => Http::response($sums),
            '*/v1.10.0/mymate-agent_v1.10.0_linux_amd64.tar.gz' => Http::response($archive),
        ]);
        $agent = $this->agent();

        Redis::shouldReceive('publish')->once()->withArgs(function (string $channel, string $json) use ($agent, $binary) {
            $msg = json_decode($json, true);

            return $msg['agent_id'] === $agent->id
                && $msg['upgrade']['version'] === 'v1.10.0'
                && $msg['upgrade']['path'] === '/agent/binary/v1.10.0/linux_amd64'
                && $msg['upgrade']['sha256'] === hash('sha256', $binary)
                && $msg['upgrade']['size'] === strlen($binary);
        });

        (new PrepareAgentUpgradeJob($agent->id, 'v1.10.0'))->handle(app(AgentReleases::class));

        $this->assertSame('sent', $agent->fresh()->upgrade_status);
        // Cached for the hub to serve.
        $this->assertSame(hash('sha256', $binary), app(AgentReleases::class)->cached('v1.10.0', 'linux/amd64')['sha256']);
    }

    public function test_a_build_that_doesnt_match_the_release_checksum_is_refused(): void
    {
        [$archive] = $this->fakeRelease('real');
        [, $otherSums] = $this->fakeRelease('something else');
        Http::fake([
            '*/SHA256SUMS' => Http::response($otherSums),
            '*.tar.gz' => Http::response($archive),
        ]);

        $this->expectExceptionMessage("doesn't match the release checksum");
        try {
            app(AgentReleases::class)->ensure('v1.10.0', 'linux/amd64');
        } finally {
            $this->assertNull(app(AgentReleases::class)->cached('v1.10.0', 'linux/amd64'));
        }
    }

    public function test_an_offline_install_can_drop_the_binary_in_by_hand(): void
    {
        Http::fake(); // nothing may be fetched
        $releases = app(AgentReleases::class);
        File::ensureDirectoryExists($releases->dir('v1.10.0', 'windows/amd64'));
        file_put_contents($releases->binaryPath('v1.10.0', 'windows/amd64'), 'exe');

        $this->assertSame(hash('sha256', 'exe'), $releases->ensure('v1.10.0', 'windows/amd64')['sha256']);
        Http::assertNothingSent();
        $this->assertNull($releases->cached('../../etc', 'linux/amd64'), 'versions are validated');
    }

    public function test_a_failed_preparation_is_reported_on_the_agent(): void
    {
        $agent = $this->agent(['upgrade_status' => 'queued']);

        (new PrepareAgentUpgradeJob($agent->id, 'v1.10.0'))->failed(new \RuntimeException('HTTP 404'));

        $this->assertSame('failed', $agent->fresh()->upgrade_status);
        $this->assertSame('HTTP 404', $agent->fresh()->upgrade_message);
    }
}
