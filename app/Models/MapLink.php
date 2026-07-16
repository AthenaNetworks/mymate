<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A manual, device-less link drawn on an overview map between two child-map nodes (GitHub #9).
 * Static topology for a top-level view - no interface, throughput, or util; just a medium and
 * an optional label.
 */
class MapLink extends Model
{
    /** Same media types a device link can carry. */
    public const MEDIA_TYPES = Link::MEDIA_TYPES;

    /** Valid drag handles (mirrors StoreLinkRequest::HANDLES). */
    public const HANDLES = ['s-top', 's-bottom', 's-left', 's-right', 't-top', 't-bottom', 't-left', 't-right'];

    protected $fillable = [
        'map_id', 'a_map_id', 'b_map_id', 'a_handle', 'b_handle', 'media_type', 'label',
    ];

    public function map(): BelongsTo
    {
        return $this->belongsTo(Map::class);
    }

    public function aMap(): BelongsTo
    {
        return $this->belongsTo(Map::class, 'a_map_id');
    }

    public function bMap(): BelongsTo
    {
        return $this->belongsTo(Map::class, 'b_map_id');
    }
}
