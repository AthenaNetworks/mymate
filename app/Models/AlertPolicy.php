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

    /**
     * What the scope covers right now (ScopeCoverage), set by the policy list for its rows. Not a
     * column: a plain property, so it's never saved or serialised with the model.
     *
     * @var array{matched: int, total: int, sample: list<string>, device_names: array<int, string>}|null
     */
    public ?array $coverage = null;

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
