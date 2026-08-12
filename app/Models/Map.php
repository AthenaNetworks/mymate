<?php

namespace App\Models;

use App\Support\Visibility;
use Database\Factories\MapFactory;
use Illuminate\Database\Eloquent\Builder;
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

    /** Restricted operators (GitHub #28) only see the maps they're granted (and their sub-maps). */
    protected static function booted(): void
    {
        static::addGlobalScope('visibility', function (Builder $query): void {
            if ($user = Visibility::restrictedUser()) {
                $query->whereIn($query->getModel()->getTable().'.id', $user->visibleMapIds());
            }
        });
    }

    protected $fillable = ['name', 'parent_map_id', 'is_default', 'position', 'node_x', 'node_y', 'leaflet_enabled', 'ping_interval'];

    protected $casts = [
        'is_default' => 'boolean',
        'position' => 'integer',
        'node_x' => 'float',
        'node_y' => 'float',
        'leaflet_enabled' => 'boolean',
        'ping_interval' => 'integer', // per-map up/down ping cadence override (s); null = global
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

    /** Manual (device-less) links drawn on this map between its child-map nodes. */
    public function mapLinks(): HasMany
    {
        return $this->hasMany(MapLink::class);
    }

    /** Free-text notes / labels placed on this map. */
    public function mapNotes(): HasMany
    {
        return $this->hasMany(MapNote::class);
    }

    /** Public read-only wallboard links for this map (GitHub #15). */
    public function shares(): HasMany
    {
        return $this->hasMany(MapShare::class);
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
