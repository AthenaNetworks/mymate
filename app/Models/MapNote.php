<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A free-text note / label placed on a map (GitHub #11) - an annotation, not tied to any device
 * or link.
 */
class MapNote extends Model
{
    protected $fillable = ['map_id', 'text', 'x', 'y', 'color', 'background', 'size'];

    protected $casts = [
        'x' => 'float',
        'y' => 'float',
    ];

    public function map(): BelongsTo
    {
        return $this->belongsTo(Map::class);
    }
}
