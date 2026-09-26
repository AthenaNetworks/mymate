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
        'device_id', 'if_index', 'name', 'description', 'speed_mbps', 'oper_status',
        'last_in', 'last_out', 'last_ts', 'util_in', 'util_out', 'bps_in', 'bps_out',
        'optical_rx_dbm', 'optical_tx_dbm', 'optical_at',
        'pkts_in', 'pkts_out', 'errors_in', 'errors_out', 'discards_in', 'discards_out', 'port_counters',
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
        'optical_rx_dbm' => 'float',
        'optical_tx_dbm' => 'float',
        'optical_at' => 'datetime',
        // latest port rates, per second (see App\Services\Polling\PortStats)
        'pkts_in' => 'float',
        'pkts_out' => 'float',
        'errors_in' => 'float',
        'errors_out' => 'float',
        'discards_in' => 'float',
        'discards_out' => 'float',
        // raw counters from the last read, {"ts": unix, "c": {name: value}}
        'port_counters' => 'array',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
