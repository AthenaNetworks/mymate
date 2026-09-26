<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A named set of operators sharing the same access (GitHub #28). A group is either read-only on
 * everything, or `restricted` to its maps (plus their sub-maps). See User::isRestricted() and
 * User::visibleMapIds() for how a member's own settings and their groups combine.
 *
 * `restricted` is a privilege like users.restricted, so it's not fillable - the controller sets it
 * explicitly after the admin gate.
 */
class UserGroup extends Model
{
    protected $fillable = ['name', 'description'];

    protected $casts = [
        'restricted' => 'boolean',
    ];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_group_user');
    }

    public function maps(): BelongsToMany
    {
        return $this->belongsToMany(Map::class, 'map_user_group');
    }
}
