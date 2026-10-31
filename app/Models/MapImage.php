<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A library image placed on a map - a logo, a legend, a photo (GitHub #53). */
class MapImage extends Model
{
    protected $fillable = ['map_id', 'image_asset_id', 'x', 'y', 'width'];

    protected $casts = [
        'x' => 'float',
        'y' => 'float',
        'width' => 'float',
    ];

    public function map(): BelongsTo
    {
        return $this->belongsTo(Map::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(ImageAsset::class, 'image_asset_id');
    }

    /** What the canvas needs to draw it. */
    public function meta(): array
    {
        return [
            'id' => $this->id,
            'map_id' => $this->map_id,
            'x' => $this->x,
            'y' => $this->y,
            'width' => $this->width,
            'asset' => $this->asset?->meta(),
        ];
    }
}
