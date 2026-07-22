<?php

namespace App\Models;

use Database\Factories\SensorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A custom SNMP sensor definition: an OID polled on the in-scope devices and stored as a
 * readings/history series. `divisor` scales the raw value; `unit` is a display suffix.
 */
class Sensor extends Model
{
    /** @use HasFactory<SensorFactory> */
    use HasFactory;

    /** How the OID is read: a scalar GET, or a table walk reduced by `agg`. */
    public const MODES = ['get', 'walk'];

    /** How a table walk is reduced to a single value. */
    public const AGGS = ['sum', 'avg', 'max', 'min', 'count'];

    protected $fillable = ['name', 'oid', 'mode', 'agg', 'unit', 'divisor', 'scope', 'enabled'];

    protected $casts = [
        'divisor' => 'float',
        'scope' => 'array',
        'enabled' => 'boolean',
    ];

    public function readings(): HasMany
    {
        return $this->hasMany(SensorReading::class);
    }
}
