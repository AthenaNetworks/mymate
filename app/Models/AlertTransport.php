<?php

namespace App\Models;

use App\Enums\TransportType;
use Database\Factories\AlertTransportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A delivery channel for alerts. `config` (email address / webhook
 * URL) is encrypted at rest and `$hidden`, so it's never serialised to the API.
 */
class AlertTransport extends Model
{
    /** @use HasFactory<AlertTransportFactory> */
    use HasFactory;

    protected $fillable = ['name', 'type', 'config', 'enabled'];

    protected $casts = [
        'type' => TransportType::class,
        'config' => 'encrypted:array',
        'enabled' => 'boolean',
    ];

    protected $hidden = ['config'];

    public function policies(): BelongsToMany
    {
        return $this->belongsToMany(AlertPolicy::class, 'alert_policy_transport');
    }
}
