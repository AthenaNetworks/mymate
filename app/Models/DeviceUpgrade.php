<?php

namespace App\Models;

use App\Enums\UpgradeStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One firmware upgrade attempt on a device. Open while `finished_at` is null. Only ever
 * written through App\Actions\Upgrade\RecordUpgradeStatus, which keeps it in step with the
 * upgrade_* columns on the device.
 */
class DeviceUpgrade extends Model
{
    protected $fillable = [
        'device_id', 'batch_id', 'user_id', 'from_version', 'to_version', 'status', 'message',
        'queued_at', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'status' => UpgradeStatus::class,
        'queued_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** Seconds from a worker picking it up (or it being queued) to it finishing, null while running. */
    public function durationSeconds(): ?int
    {
        $from = $this->started_at ?? $this->queued_at;
        if ($from === null || $this->finished_at === null) {
            return null;
        }

        return (int) $from->diffInSeconds($this->finished_at, true);
    }
}
