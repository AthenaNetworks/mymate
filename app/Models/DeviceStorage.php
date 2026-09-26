<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One storage entry on a device as of the last metrics poll: a disk, RAM, swap, flash. Written
 * by App\Actions\Polling\RecordDeviceResources; its history is the `storage` family
 * (storage_samples, keyed by this id).
 *
 * storage_key is the hrStorageIndex over SNMP, or "memory" / "system-disk" over the RouterOS API.
 * type is one of StorageReading::KEPT_TYPES (ram, virtual_memory, fixed_disk, flash, ...).
 */
class DeviceStorage extends Model
{
    protected $fillable = ['device_id', 'storage_key', 'descr', 'type', 'size_bytes', 'used_bytes', 'used_pct'];

    protected $casts = [
        'size_bytes' => 'integer',
        'used_bytes' => 'integer',
        'used_pct' => 'float',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
