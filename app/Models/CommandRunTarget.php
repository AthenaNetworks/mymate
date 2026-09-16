<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommandRunTarget extends Model
{
    protected $fillable = [
        'run_id', 'device_id', 'device_name', 'status', 'output', 'error',
        'duration_ms', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'duration_ms' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(CommandRun::class, 'run_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
