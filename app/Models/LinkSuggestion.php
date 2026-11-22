<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A link neighbour discovery thinks exists (GitHub #85), waiting for an operator to accept or
 * ignore it. See the create_link_suggestions migration for how a pair is keyed.
 */
class LinkSuggestion extends Model
{
    public const PROTOCOLS = ['lldp', 'cdp', 'mndp'];

    protected $fillable = [
        'a_device_id', 'a_interface_id', 'b_device_id', 'b_interface_id',
        'pair_key', 'protocol', 'status', 'first_seen_at', 'last_seen_at',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => 'new',
    ];

    public static function key(int $aDevice, ?int $aInterface, int $bDevice, ?int $bInterface): string
    {
        return $aDevice.':'.($aInterface ?? '-').':'.$bDevice.':'.($bInterface ?? '-');
    }

    public function aDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'a_device_id');
    }

    public function bDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'b_device_id');
    }

    public function aInterface(): BelongsTo
    {
        return $this->belongsTo(NetworkInterface::class, 'a_interface_id');
    }

    public function bInterface(): BelongsTo
    {
        return $this->belongsTo(NetworkInterface::class, 'b_interface_id');
    }
}
