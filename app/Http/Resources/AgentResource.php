<?php

namespace App\Http\Resources;

use App\Support\UpdateChecker;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Cache;

/**
 * Remote agent for the Agents UI. The bearer token is never returned (only its hash is
 * stored, and even that is `$hidden`); the plaintext is surfaced once, by the store
 * endpoint, at enrolment time.
 */
class AgentResource extends JsonResource
{
    /** Memoised for the request so a roster of agents resolves the server version once. */
    private static ?string $serverVersion = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status->value,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'version' => $this->version,
            'platform' => $this->platform,
            // Current link latency (ms), measured from the hub's keepalive ping/pong. Null when the
            // agent hasn't answered a keepalive recently (the cache entry expires), i.e. it's offline.
            'latency_ms' => Cache::get("agent:{$this->id}:latency"),
            // The agent tracks the server version; flag it when it's behind so the UI can prompt an
            // update. 'dev' server builds (unknown version) never flag.
            'outdated' => $this->isOutdated(),
            'device_count' => $this->devices_count ?? 0,
            'subnet_count' => $this->subnets_count ?? 0,
        ];
    }

    private function isOutdated(): bool
    {
        $server = self::$serverVersion ??= app(UpdateChecker::class)->current();

        return $this->version !== null && $server !== 'dev'
            && version_compare(ltrim($this->version, 'vV'), $server, '<');
    }
}
