<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Places a device on a map at per-map coordinates. The
 * `(device_id, map_id)` pair is unique - one position per device per map.
 */
class DeviceMapPosition extends Model
{
    protected $fillable = ['device_id', 'map_id', 'x', 'y'];

    protected $casts = [
        'x' => 'float',
        'y' => 'float',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function map(): BelongsTo
    {
        return $this->belongsTo(Map::class);
    }
}
