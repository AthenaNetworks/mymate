<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A fired alert. One open event per `dedupe_key` provides dedupe.
 * Lifecycle: `pending` ->
 * `firing` (notified) -> `resolved` (condition cleared). Instant policies skip `pending`.
 */
class AlertEvent extends Model
{
    protected $fillable = ['alert_policy_id', 'dedupe_key', 'status', 'message', 'delivered', 'breach_started_at', 'fired_at', 'recovery_started_at', 'resolved_at', 'acknowledged_at', 'acknowledged_by'];

    protected $casts = [
        'delivered' => 'boolean',
        'breach_started_at' => 'datetime',
        'fired_at' => 'datetime',
        'recovery_started_at' => 'datetime',
        'resolved_at' => 'datetime',
        'acknowledged_at' => 'datetime',
    ];

    public function policy(): BelongsTo
    {
        return $this->belongsTo(AlertPolicy::class, 'alert_policy_id');
    }

    /** Who acknowledged it (soft ref - no FK, users may be pruned). */
    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }
}
