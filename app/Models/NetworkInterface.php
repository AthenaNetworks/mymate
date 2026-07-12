<?php

namespace App\Models;

use Database\Factories\NetworkInterfaceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NetworkInterface extends Model
{
    /** @use HasFactory<NetworkInterfaceFactory> */
    use HasFactory;

    // The class cannot be named "Interface" (reserved PHP word); pin the table explicitly.
    protected $table = 'interfaces';

    protected $fillable = [
        'device_id', 'if_index', 'name', 'description', 'speed_mbps',
        'last_in', 'last_out', 'last_ts', 'util_in', 'util_out', 'bps_in', 'bps_out',
    ];

    protected $casts = [
        'if_index' => 'integer',
        'speed_mbps' => 'integer',
        'last_in' => 'integer',
        'last_out' => 'integer',
        'last_ts' => 'datetime',
        'util_in' => 'float',
        'util_out' => 'float',
        'bps_in' => 'integer',
        'bps_out' => 'integer',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
