<?php

namespace App\Models;

use App\Enums\SiteKind;
use Database\Factories\SiteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A physical location devices live at (see the create_sites_table migration for the why).
 *
 * A site holds coordinates once; the devices at it inherit them, so the geo map can place a
 * whole tower's worth of gear without anyone dragging pins.
 */
class Site extends Model
{
    /** @use HasFactory<SiteFactory> */
    use HasFactory;

    protected $fillable = [
        'name', 'kind', 'latitude', 'longitude', 'address', 'external_ref', 'note',
    ];

    protected $casts = [
        'kind' => SiteKind::class,
        'latitude' => 'float',
        'longitude' => 'float',
    ];

    protected $attributes = [
        'kind' => 'other',
    ];

    /** @return HasMany<Device, $this> */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /** A site is only usable for placement once it has both coordinates. */
    public function isPlaced(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /**
     * Great-circle distance to a point, in kilometres (haversine).
     *
     * Good enough for "which tower is this device at" - the error against a proper geodesic
     * is metres over the tens-of-kilometres ranges that matter here, and it needs no
     * PostGIS/spatial extension, which keeps a stock Postgres install working.
     */
    public function distanceKmTo(float $lat, float $lng): ?float
    {
        if (! $this->isPlaced()) {
            return null;
        }

        $earthRadiusKm = 6371.0088;
        $dLat = deg2rad($lat - $this->latitude);
        $dLng = deg2rad($lng - $this->longitude);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($this->latitude)) * cos(deg2rad($lat)) * sin($dLng / 2) ** 2;

        return $earthRadiusKm * 2 * asin(min(1.0, sqrt($a)));
    }
}
