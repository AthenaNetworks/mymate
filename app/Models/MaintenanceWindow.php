<?php

namespace App\Models;

use Database\Factories\MaintenanceWindowFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A scheduled maintenance window. While active (enabled and now within [starts_at,
 * ends_at)), alerts are suppressed for the devices its `scope` covers.
 */
class MaintenanceWindow extends Model
{
    /** @use HasFactory<MaintenanceWindowFactory> */
    use HasFactory;

    protected $fillable = ['name', 'starts_at', 'ends_at', 'scope', 'enabled', 'created_by'];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'scope' => 'array',
        'enabled' => 'boolean',
    ];

    /** Windows that are enabled and currently in effect. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('enabled', true)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now());
    }

    /** Convenience for a single row: is this window in effect right now? */
    public function isActive(): bool
    {
        return $this->enabled && $this->starts_at <= now() && $this->ends_at > now();
    }
}
