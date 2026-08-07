<?php

namespace App\Models;

use App\Enums\DeviceStatus;
use App\Enums\ProbeKind;
use Database\Factories\ProbeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A service probe attached to a device (GitHub #19): an HTTP(S) or TCP check with its own up/down,
 * latency and (for HTTPS) certificate expiry. `config` holds the kind-specific settings - for HTTP
 * a url/method/expected status/keyword, for TCP a host/port.
 */
class Probe extends Model
{
    /** @use HasFactory<ProbeFactory> */
    use HasFactory;

    protected $fillable = [
        'device_id', 'name', 'kind', 'enabled', 'interval_s', 'timeout_ms', 'fail_threshold', 'config',
    ];

    protected $casts = [
        'kind' => ProbeKind::class,
        'status' => DeviceStatus::class,
        'enabled' => 'boolean',
        'interval_s' => 'integer',
        'timeout_ms' => 'integer',
        'fail_threshold' => 'integer',
        'fail_streak' => 'integer',
        'latency_ms' => 'float',
        'config' => 'array',
        'cert_expires_at' => 'datetime',
        'checked_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** Due for a check: never run, or its interval has elapsed since the last run. */
    public function isDue(): bool
    {
        return $this->checked_at === null
            || $this->checked_at->addSeconds(max(5, $this->interval_s))->isPast();
    }
}
