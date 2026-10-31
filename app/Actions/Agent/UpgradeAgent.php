<?php

namespace App\Actions\Agent;

use App\Enums\AgentStatus;
use App\Jobs\PrepareAgentUpgradeJob;
use App\Models\Agent;
use App\Services\Agent\AgentReleases;
use App\Support\UpdateChecker;
use Illuminate\Validation\ValidationException;

/**
 * Ask an agent to upgrade itself to the version that matches this server. Only for an agent that
 * says it can (agent/COMPATIBILITY.md); the agent verifies the download, runs the new version under
 * its own supervisor, and rolls back by itself if that version doesn't stay connected.
 */
class UpgradeAgent
{
    public function __construct(private UpdateChecker $versions) {}

    public function target(): ?string
    {
        $v = $this->versions->current();

        return $v === 'dev' ? null : 'v'.$v;
    }

    /** Why this agent can't be upgraded right now, or null when it can. */
    public function blocker(Agent $agent): ?string
    {
        $target = $this->target();

        return match (true) {
            $target === null => "This server is a development build, so there's no matching agent release.",
            ! $agent->supports(Agent::CAP_UPGRADE) => 'This agent can\'t upgrade itself (it\'s an older version, runs in a container, or has no writable state folder). Upgrade it by hand.',
            $agent->status !== AgentStatus::Online || $agent->isDown() => 'The agent is offline.',
            ! AgentReleases::validPlatform((string) $agent->platform) => "There's no agent build for {$agent->platform}.",
            $agent->version !== null && version_compare(ltrim($agent->version, 'vV'), ltrim($target, 'v'), '>=') => "It's already on {$agent->version}.",
            in_array($agent->upgrade_status, ['queued', 'sent', 'downloading', 'restarting'], true)
                && $agent->upgrade_at?->gt(now()->subMinutes(15)) => 'An upgrade is already in progress.',
            default => null,
        };
    }

    public function __invoke(Agent $agent): void
    {
        if ($why = $this->blocker($agent)) {
            throw ValidationException::withMessages(['agent' => $why]);
        }
        $agent->forceFill([
            'upgrade_status' => 'queued', 'upgrade_version' => $this->target(),
            'upgrade_message' => null, 'upgrade_at' => now(),
        ])->save();
        PrepareAgentUpgradeJob::dispatch($agent->id, $this->target());
    }
}
