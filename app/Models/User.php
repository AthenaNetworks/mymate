<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * An operator account. Auth is Sanctum SPA (stateful cookie session);
 * `HasApiTokens` is kept for optional personal access tokens.  adds a single
 * `is_admin` tier: admins manage operator accounts, normal operators are view-only.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    // NOTE: `is_admin` is deliberately NOT fillable - the admin tier is a privilege, so
    // it's set explicitly in the controller *after* the admin gate, never from a mass-
    // assigned payload. An operator cannot self-escalate by smuggling it into a request.
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed', // auto-hash on set
        'is_admin' => 'boolean',
        // Like is_admin, `restricted` is a privilege set explicitly after the admin gate.
        'restricted' => 'boolean',
    ];

    /** @var array<int>|null memoised per-request visibility sets */
    private ?array $memoMapIds = null;

    private ?array $memoDeviceIds = null;

    /** Admins can manage operator accounts; normal operators are view-only. */
    public function isAdmin(): bool
    {
        return (bool) $this->is_admin;
    }

    /** A restricted operator only sees the maps they've been granted (GitHub #28). */
    public function isRestricted(): bool
    {
        return (bool) $this->restricted;
    }

    /** Maps explicitly granted to this operator. */
    public function maps(): BelongsToMany
    {
        return $this->belongsToMany(Map::class, 'map_user');
    }

    /**
     * Map ids this operator may see: the granted maps plus all their sub-maps (grant a region,
     * see its towns). Computed without the visibility scope to avoid recursing through it, and
     * memoised for the request.
     *
     * @return array<int>
     */
    public function visibleMapIds(): array
    {
        if ($this->memoMapIds !== null) {
            return $this->memoMapIds;
        }

        // withoutGlobalScopes so resolving the grant doesn't recurse through the visibility scope.
        $granted = $this->maps()->withoutGlobalScopes()->pluck('maps.id')->all();

        // Walk the full map tree (unscoped) to add descendants of each granted map.
        $childrenOf = [];
        foreach (Map::withoutGlobalScopes()->get(['id', 'parent_map_id']) as $m) {
            $childrenOf[$m->parent_map_id][] = $m->id;
        }

        $out = [];
        $seen = [];
        $stack = $granted;
        while ($stack !== []) {
            $id = (int) array_pop($stack);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = $id;
            foreach ($childrenOf[$id] ?? [] as $child) {
                $stack[] = $child;
            }
        }

        return $this->memoMapIds = $out;
    }

    /**
     * Device ids this operator may see: every device placed on a visible map.
     *
     * @return array<int>
     */
    public function visibleDeviceIds(): array
    {
        if ($this->memoDeviceIds !== null) {
            return $this->memoDeviceIds;
        }

        return $this->memoDeviceIds = DeviceMapPosition::whereIn('map_id', $this->visibleMapIds())
            ->pluck('device_id')->unique()->map(fn ($id) => (int) $id)->all();
    }
}
