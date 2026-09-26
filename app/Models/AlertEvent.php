<?php

namespace App\Models;

use App\Events\AlertStateChanged;
use App\Support\LiveBroadcast;
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

    /**
     * Push a live notice when an alert starts firing or a firing one resolves (GitHub #22), so the
     * map screen can pop up a port going down like it does a device outage. Hooked on the model
     * rather than in the evaluator so every path that fires or resolves an alert is covered. A breach
     * that resolves while still pending was never notified, so it stays quiet here too.
     */
    protected static function booted(): void
    {
        // Separate hooks on purpose: wasRecentlyCreated stays true on the instance, so a later save
        // of the same model (marking it delivered) would look like a fresh fire under `saved`.
        static::created(function (AlertEvent $event): void {
            if ($event->status === 'firing') {
                $event->announce('firing');
            }
        });
        static::updated(function (AlertEvent $event): void {
            if (! $event->wasChanged('status')) {
                return;
            }
            if ($event->status === 'firing') {
                $event->announce('firing');
            } elseif ($event->status === 'resolved' && $event->getOriginal('status') === 'firing') {
                $event->announce('resolved');
            }
        });
    }

    /** @param  'firing'|'resolved'  $state */
    private function announce(string $state): void
    {
        $condition = $this->policy()->value('condition');
        LiveBroadcast::send(new AlertStateChanged($this, $state, $condition instanceof \BackedEnum ? $condition->value : $condition));
    }

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
