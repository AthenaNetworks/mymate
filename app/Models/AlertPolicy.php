<?php

namespace App\Models;

use App\Enums\AlertCondition;
use Database\Factories\AlertPolicyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An alert policy: fire on a `condition` (with optional `params`)
 * and deliver via the attached transports.
 */
class AlertPolicy extends Model
{
    /** @use HasFactory<AlertPolicyFactory> */
    use HasFactory;

    protected $fillable = ['name', 'condition', 'params', 'scope', 'enabled'];

    protected $casts = [
        'condition' => AlertCondition::class,
        'params' => 'array',
        'scope' => 'array', // targeting bag; null = all devices
        'enabled' => 'boolean',
    ];

    public function transports(): BelongsToMany
    {
        return $this->belongsToMany(AlertTransport::class, 'alert_policy_transport');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AlertEvent::class);
    }
}
