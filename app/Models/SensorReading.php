<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The current value of one sensor on one device (upserted each poll). The trend lives in
 * the partitioned sensor_samples table. Composite key (sensor_id, device_id), no timestamps.
 */
class SensorReading extends Model
{
    protected $table = 'sensor_readings';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['sensor_id', 'device_id', 'value', 'read_at'];

    protected $casts = [
        'value' => 'float',
        'read_at' => 'datetime',
    ];

    public function sensor(): BelongsTo
    {
        return $this->belongsTo(Sensor::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
