<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry on a map's layout undo stack: the device positions captured just before an auto-tidy
 * re-arranged them. Popping the newest one restores that layout. `created_at` only (immutable).
 */
class MapLayoutSnapshot extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['map_id', 'positions', 'note'];

    protected $casts = [
        'positions' => 'array',
    ];

    public function map(): BelongsTo
    {
        return $this->belongsTo(Map::class);
    }
}
