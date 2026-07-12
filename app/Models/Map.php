<?php

namespace App\Models;

use Database\Factories\MapFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A topology map. Nestable via `parent_map_id` (town -> region);
 * devices are placed on it (with per-map coordinates) through `device_map_positions`.
 */
class Map extends Model
{
    /** @use HasFactory<MapFactory> */
    use HasFactory;

    protected $fillable = ['name', 'parent_map_id', 'is_default', 'position'];

    protected $casts = [
        'is_default' => 'boolean',
        'position' => 'integer',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_map_id');
    }

    /** @return HasMany<Map, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_map_id');
    }

    public function positions(): HasMany
    {
        return $this->hasMany(DeviceMapPosition::class);
    }

    public function devices(): BelongsToMany
    {
        return $this->belongsToMany(Device::class, 'device_map_positions')->withPivot('x', 'y');
    }

    /** The default map new devices join (the flagged one, else the oldest). */
    public static function default(): ?self
    {
        return static::where('is_default', true)->first() ?? static::orderBy('id')->first();
    }
}
