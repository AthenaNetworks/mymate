<?php

namespace App\Jobs;

use App\Actions\Agent\IngestAgentResults;
use App\Actions\Agent\IngestAgentScan;
use App\Models\Agent;
use App\Support\EngineLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Thin queue envelope: apply one batch an agent sent (poll results or a discovery scan). The hub
 * hands these off instead of writing to the DB inside its event loop, where a big site's batch
 * held up every other agent's frames and keepalives. Runs on the single-process `agent` queue so
 * an agent's batches are applied in arrival order. tries=1: a stale batch is worse than a skipped one.
 */
class IngestAgentPayloadJob implements ShouldQueue
{
    use Queueable;

    public const RESULTS = 'result';

    public const SCAN = 'discovery';

    public int $tries = 1;

    /** @param  array<string, mixed>  $payload */
    public function __construct(public int $agentId, public string $kind, public array $payload)
    {
        $this->onQueue('agent');
    }

    public function handle(IngestAgentResults $results, IngestAgentScan $scan): void
    {
        $agent = Agent::find($this->agentId);
        if ($agent === null) {
            return; // deleted since it sent this - nothing of its may be written
        }
        $this->kind === self::SCAN ? $scan($agent, $this->payload) : $results($agent, $this->payload);
    }

    public function failed(Throwable $e): void
    {
        EngineLog::warning('agent: '.($this->kind === self::SCAN ? 'scan' : 'result').' ingest failed', [
            'id' => $this->agentId,
            'error' => $e->getMessage(),
        ]);
    }
}
