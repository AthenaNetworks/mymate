<?php

namespace App\Jobs;

use App\Actions\Agent\DispatchAgentJobs;
use App\Models\Agent;
use App\Services\Agent\AgentReleases;
use App\Support\EngineLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Get the agent build for an upgrade (from the cache, or the release) and send the agent its
 * "upgrade" message through the hub. From there the agent reports its own progress
 * (upgrade_status) - this job only gets it started.
 */
class PrepareAgentUpgradeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $agentId, public string $version)
    {
        // The long-running queue: a cold download from the release can outlast the default
        // queue's retry window.
        $this->onQueue('upgrade');
    }

    public function handle(AgentReleases $releases): void
    {
        $agent = Agent::find($this->agentId);
        if ($agent === null) {
            return;
        }
        $bin = $releases->ensure($this->version, (string) $agent->platform);

        Redis::publish(DispatchAgentJobs::CHANNEL, json_encode([
            'agent_id' => $agent->id,
            'upgrade' => [
                'version' => $this->version,
                'path' => '/agent/binary/'.$this->version.'/'.str_replace('/', '_', (string) $agent->platform),
                'sha256' => $bin['sha256'],
                'size' => $bin['size'],
            ],
        ]));
        $agent->forceFill(['upgrade_status' => 'sent', 'upgrade_at' => now()])->save();
        EngineLog::info('agent upgrade sent', ['agent' => $agent->name, 'version' => $this->version]);
    }

    public function failed(Throwable $e): void
    {
        Agent::whereKey($this->agentId)->update([
            'upgrade_status' => 'failed', 'upgrade_message' => mb_substr($e->getMessage(), 0, 500), 'upgrade_at' => now(),
        ]);
        EngineLog::warning('agent upgrade: preparing failed', ['id' => $this->agentId, 'error' => $e->getMessage()]);
    }
}
