<?php

namespace App\Models;

use App\Enums\AgentStatus;
use Database\Factories\AgentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A remote agent (probe). Polls + discovers devices on its local management network and
 * relays results over an outbound WebSocket. Authenticates with a bearer token, of which
 * we persist only the SHA-256 hash - the plaintext is returned once by {@see enrol()} and
 * never again (same model as an API token).
 */
class Agent extends Model
{
    /** @use HasFactory<AgentFactory> */
    use HasFactory;

    protected $fillable = ['name', 'status', 'last_seen_at', 'version', 'platform'];

    protected $casts = [
        'status' => AgentStatus::class,
        'last_seen_at' => 'datetime',
    ];

    // token_hash is a secret - never expose it in arrays/JSON.
    protected $hidden = ['token_hash'];

    /** Devices this agent polls. */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /** Subnets this agent scans for discovery. */
    public function subnets(): HasMany
    {
        return $this->hasMany(Subnet::class);
    }

    /**
     * Create an agent and return [model, plaintextToken]. The token is shown once; only its
     * hash is stored. The caller surfaces the plaintext to the operator to configure the agent.
     *
     * @return array{0: self, 1: string}
     */
    public static function enrol(string $name): array
    {
        $token = 'mma_'.Str::random(48); // "my mate agent" - recognisable, high-entropy
        $agent = new self(['name' => $name, 'status' => AgentStatus::Enrolled]);
        $agent->token_hash = self::hashToken($token); // not fillable - set directly, before insert
        $agent->save();

        return [$agent, $token];
    }

    /** Resolve an agent from a presented bearer token (constant-time via the unique hash lookup). */
    public static function fromToken(?string $token): ?self
    {
        if ($token === null || $token === '') {
            return null;
        }

        return self::where('token_hash', self::hashToken($token))->first();
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
