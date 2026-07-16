<?php

namespace App\Http\Resources;

use App\Models\Credential;
use App\Models\DiscoveryCandidate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/** @mixin DiscoveryCandidate */
class DiscoveryCandidateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ip' => $this->ip,
            'status' => $this->status->value,
            'sysname' => $this->sysname,
            'detected_method' => $this->detected_method?->value,
            'matched_credential_id' => $this->matched_credential_id,
            // Every credential that authenticated (SNMP + RouterOS + SSH), for tags in the queue.
            'matched_credentials' => $this->matchedCredentials(),
            'first_seen' => $this->first_seen,
            'last_seen' => $this->last_seen,
        ];
    }

    /**
     * Resolve the matched credential ids to {id, name, type} for display. Falls back to the
     * single poll/ssh columns for candidates found before the full list was recorded.
     *
     * @return list<array{id:int, name:string, type:string}>
     */
    private function matchedCredentials(): array
    {
        $ids = $this->matched_credential_ids ?: array_values(array_filter([
            $this->matched_credential_id,
            $this->matched_ssh_credential_id,
        ]));

        $map = self::credentialMap();

        return collect($ids)
            ->map(fn ($id): ?Credential => $map->get((int) $id))
            ->filter()
            ->map(fn (Credential $c): array => ['id' => $c->id, 'name' => $c->name, 'type' => $c->type])
            ->values()
            ->all();
    }

    /** Credentials keyed by id, loaded once per request (the pool is small). */
    private static function credentialMap(): Collection
    {
        static $map = null;

        return $map ??= Credential::query()->get(['id', 'name', 'type'])->keyBy('id');
    }
}
