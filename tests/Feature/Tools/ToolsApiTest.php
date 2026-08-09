<?php

namespace Tests\Feature\Tools;

use App\Jobs\Tools\RunPingJob;
use App\Jobs\Tools\RunPortScanJob;
use App\Jobs\Tools\RunSweepJob;
use App\Models\User;
use App\Services\Tools\ToolRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ToolsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_non_admin_operator_can_start_a_ping_and_it_seeds_the_run_and_records_the_owner(): void
    {
        Queue::fake();
        $operator = User::factory()->create(['is_admin' => false]); // read-only tier, still allowed these
        $this->actingAs($operator);

        $body = $this->postJson('/api/tools/ping', ['target' => '1.1.1.1', 'count' => 5])
            ->assertStatus(202)
            ->assertJson(['kind' => 'ping', 'status' => 'running'])
            ->json();

        Queue::assertPushed(RunPingJob::class, fn ($job) => $job->target === '1.1.1.1' && $job->count === 5);
        $this->assertNotNull(ToolRun::get($body['run_id']));
        $this->assertSame($operator->id, ToolRun::owner($body['run_id']));
    }

    public function test_ping_rejects_an_invalid_target(): void
    {
        Queue::fake();
        $this->actingAsUser();

        $this->postJson('/api/tools/ping', ['target' => 'bad;rm -rf /'])->assertStatus(422);
        Queue::assertNothingPushed();
    }

    public function test_sweep_rejects_a_non_cidr_and_an_oversized_range(): void
    {
        Queue::fake();
        $this->actingAsUser();

        $this->postJson('/api/tools/sweep', ['cidr' => '192.168.1.1'])->assertStatus(422);
        $this->postJson('/api/tools/sweep', ['cidr' => '10.0.0.0/8'])->assertStatus(422); // over max_sweep_hosts
        Queue::assertNothingPushed();
    }

    public function test_sweep_passes_common_ports_only_when_port_scanning_is_requested(): void
    {
        Queue::fake();
        $this->actingAsUser();

        $this->postJson('/api/tools/sweep', ['cidr' => '192.168.1.0/30'])->assertStatus(202);
        Queue::assertPushed(RunSweepJob::class, fn ($job) => $job->ports === []);

        $this->postJson('/api/tools/sweep', ['cidr' => '192.168.1.0/30', 'scan_ports' => true])->assertStatus(202);
        Queue::assertPushed(RunSweepJob::class, fn ($job) => $job->ports !== []);
    }

    public function test_port_scan_parses_a_custom_port_list(): void
    {
        Queue::fake();
        $this->actingAsUser();

        $this->postJson('/api/tools/portscan', ['target' => '10.0.0.1', 'ports' => '22, 80, 443, 22'])
            ->assertStatus(202);

        Queue::assertPushed(RunPortScanJob::class, fn ($job) => $job->ports === [22, 80, 443]); // deduped
    }

    public function test_show_404s_for_an_unknown_run(): void
    {
        $this->actingAsUser();
        $this->getJson('/api/tools/runs/does-not-exist')->assertNotFound();
    }

    public function test_only_the_starter_or_an_admin_can_stop_a_run(): void
    {
        Queue::fake();
        $starter = User::factory()->create(['is_admin' => false]);
        $other = User::factory()->create(['is_admin' => false]);
        $admin = User::factory()->create(['is_admin' => true]);

        $runId = $this->actingAs($starter)->postJson('/api/tools/ping', ['target' => '1.1.1.1'])->json('run_id');

        $this->actingAs($other)->deleteJson("/api/tools/runs/{$runId}")->assertForbidden();
        $this->assertFalse(ToolRun::stopRequested($runId));

        $this->actingAs($admin)->deleteJson("/api/tools/runs/{$runId}")->assertNoContent();
        $this->assertTrue(ToolRun::stopRequested($runId));
    }

    public function test_bgp_lookup_validates_input(): void
    {
        $this->actingAsUser();
        $this->postJson('/api/tools/bgp', ['query' => ''])->assertStatus(422);
    }

    public function test_tools_require_authentication(): void
    {
        $this->postJson('/api/tools/ping', ['target' => '1.1.1.1'])->assertUnauthorized();
    }
}
