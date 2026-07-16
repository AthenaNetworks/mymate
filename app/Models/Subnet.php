<?php

namespace App\Models;

use Database\Factories\SubnetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An operator-authorized IP range the discovery worker sweeps for new devices
 *. Per-subnet `scan_interval_s` controls cadence; the loop dispatches a
 * ScanSubnetJob when a subnet is due. An `agent_id` delegates the scan to a remote
 * agent so a subnet inside a management network is discovered from the inside.
 */
class Subnet extends Model
{
    /** @use HasFactory<SubnetFactory> */
    use HasFactory;

    protected $fillable = ['cidr', 'label', 'enabled', 'scan_interval_s', 'last_scanned_at', 'scanning_since', 'scan_total', 'scan_swept', 'scan_found', 'agent_id'];

    protected $casts = [
        'enabled' => 'boolean',
        'scan_interval_s' => 'integer',
        'last_scanned_at' => 'datetime',
        'scanning_since' => 'datetime',
    ];

    // Mirror the DB defaults on a freshly created instance.
    protected $attributes = [
        'enabled' => true,
        'scan_interval_s' => 3600,
    ];

    /** The remote agent that scans this subnet, or null when scanned centrally. */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
