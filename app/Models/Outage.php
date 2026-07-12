<?php

namespace App\Models;

use Database\Factories\OutageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A device down->up event. Open while `ended_at` is null; closed
 * with a `duration_s` on recovery. Written off the up/down path by `RecordOutage`.
 */
class Outage extends Model
{
    /** @use HasFactory<OutageFactory> */
    use HasFactory;

    protected $fillable = ['device_id', 'started_at', 'ended_at', 'duration_s', 'cause'];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'duration_s' => 'integer',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
