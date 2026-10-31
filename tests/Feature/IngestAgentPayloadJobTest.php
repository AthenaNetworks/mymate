<?php

namespace Tests\Feature;

use App\Actions\Agent\IngestAgentResults;
use App\Actions\Agent\IngestAgentScan;
use App\Jobs\IngestAgentPayloadJob;
use App\Models\Agent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The hub queues agent batches onto the single-process `agent` queue instead of ingesting inline. */
class IngestAgentPayloadJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_routes_each_kind_to_its_ingest_on_the_agent_queue(): void
    {
        $agent = Agent::factory()->create();
        $results = $this->mock(IngestAgentResults::class);
        $results->shouldReceive('__invoke')->once()->withArgs(fn (Agent $a, array $p) => $a->is($agent) && $p === ['pings' => []]);
        $scan = $this->mock(IngestAgentScan::class);
        $scan->shouldReceive('__invoke')->once()->withArgs(fn (Agent $a, array $p) => $a->is($agent) && $p === ['subnets' => []]);

        $job = new IngestAgentPayloadJob($agent->id, IngestAgentPayloadJob::RESULTS, ['pings' => []]);
        $this->assertSame('agent', $job->queue);
        $job->handle($results, $scan);
        (new IngestAgentPayloadJob($agent->id, IngestAgentPayloadJob::SCAN, ['subnets' => []]))->handle($results, $scan);
    }

    public function test_a_batch_from_an_agent_deleted_since_is_dropped(): void
    {
        $agent = Agent::factory()->create();
        $id = $agent->id;
        $agent->delete();
        $results = $this->mock(IngestAgentResults::class);
        $results->shouldNotReceive('__invoke');

        (new IngestAgentPayloadJob($id, IngestAgentPayloadJob::RESULTS, ['pings' => []]))->handle($results, app(IngestAgentScan::class));
    }
}
