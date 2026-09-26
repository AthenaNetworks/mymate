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
    /** What a share can show (GitHub #37): the logical diagram, the geo map, or both with a switcher. */
    public const VIEWS = ['logical', 'geo', 'both'];

    protected $fillable = ['map_id', 'token', 'label', 'view', 'enabled', 'last_viewed_at'];

    protected $attributes = [
        'view' => 'logical',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'last_viewed_at' => 'datetime',
    ];

    public function map(): BelongsTo
    {
        return $this->belongsTo(Map::class);
    }

    /**
     * Whether this share may show the geo map. Coordinates only ever leave through a share that
     * says so - a logical-only link hands out exactly what it did before geo sharing existed.
     */
    public function showsGeo(): bool
    {
        return in_array($this->view, ['geo', 'both'], true);
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
