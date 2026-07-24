<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A public, read-only share of a map's wallboard (GitHub #15). The `token` is the whole
 * capability - anyone with the link can view the map, so it is generated with enough entropy
 * to be unguessable and can be revoked by disabling or deleting the row.
 */
class MapShare extends Model
{
    protected $fillable = ['map_id', 'token', 'label', 'enabled', 'last_viewed_at'];

    protected $casts = [
        'enabled' => 'boolean',
        'last_viewed_at' => 'datetime',
    ];

    public function map(): BelongsTo
    {
        return $this->belongsTo(Map::class);
    }

    /** A fresh URL-safe token (256 bits of entropy, ~43 chars). */
    public static function newToken(): string
    {
        return Str::random(48);
    }

    /** The public wallboard URL for this share. */
    public function url(): string
    {
        return url('/wall/'.$this->token);
    }
}
