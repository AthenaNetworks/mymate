<?php

namespace App\Models;

use App\Enums\BackupStatus;
use App\Enums\DeviceStatus;
use App\Enums\DeviceType;
use App\Enums\PollMethod;
use App\Enums\UpgradeStatus;
use Database\Factories\DeviceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Device extends Model
{
    /** @use HasFactory<DeviceFactory> */
    use HasFactory;

    protected $fillable = [
        'name', 'mgmt_ip', 'poll_method', 'credential_id', 'ssh_credential_id', 'routeros_credential_id', 'agent_id',
        'status', 'monitored', 'last_change', 'map_x', 'map_y', 'latitude', 'longitude', 'geo_source',
        'device_type', 'icon', 'icon_color', 'parent_device_id', 'vendor', 'model', 'serial', 'cpu', 'ram_bytes', 'arch', 'uptime_seconds', 'uptime_at',
        'os_version', 'latest_version', 'upgrade_status', 'upgrade_message', 'upgrade_at',
        'discovery_error', 'discovered_at',
        'cpu_pct', 'mem_used_pct', 'temp_c', 'metrics_at',
        'signal_dbm', 'snr_db', 'ccq_pct', 'wireless_clients', 'ospf_neighbors',
        'rtt_ms', 'loss_pct', 'ping_at', 'latency_good_ms', 'latency_bad_ms',
        'backup_enabled', 'backup_driver', 'backup_status', 'backup_message', 'backup_at', 'backup_commit',
    ];

    protected $casts = [
        'poll_method' => PollMethod::class,
        'status' => DeviceStatus::class,
        'monitored' => 'boolean',
        'device_type' => DeviceType::class,
        'upgrade_status' => UpgradeStatus::class,
        'last_change' => 'datetime',
        'map_x' => 'float',
        'map_y' => 'float',
        'latitude' => 'float',
        'longitude' => 'float',
        'uptime_seconds' => 'integer',
        'ram_bytes' => 'integer',
        'uptime_at' => 'datetime',
        'cpu_pct' => 'float',
        'mem_used_pct' => 'float',
        'temp_c' => 'float',
        'metrics_at' => 'datetime',
        'signal_dbm' => 'float',
        'snr_db' => 'float',
        'ccq_pct' => 'float',
        'wireless_clients' => 'integer',
        'ospf_neighbors' => 'integer',
        'rtt_ms' => 'float',
        'loss_pct' => 'float',
        'ping_at' => 'datetime',
        'latency_good_ms' => 'integer',
        'latency_bad_ms' => 'integer',
        'upgrade_at' => 'datetime',
        'discovered_at' => 'datetime',
        'backup_enabled' => 'boolean',
        'backup_status' => BackupStatus::class,
        'backup_at' => 'datetime',
    ];

    // In-memory defaults so a freshly created model mirrors the DB defaults
    // (otherwise status/device_type are null on the returned instance until refreshed).
    protected $attributes = [
        'status' => 'unknown',
        'monitored' => true,
        'device_type' => 'unknown',
        'map_x' => 0,
        'map_y' => 0,
        'backup_enabled' => false,
    ];

    public function credential(): BelongsTo
    {
        return $this->belongsTo(Credential::class);
    }

    /** Dedicated SSH credential for config backups (separate from the poll credential). */
    public function sshCredential(): BelongsTo
    {
        return $this->belongsTo(Credential::class, 'ssh_credential_id');
    }

    /** Optional RouterOS-API credential for reads SNMP can't do (OSPF neighbours), or null. */
    public function routerosCredential(): BelongsTo
    {
        return $this->belongsTo(Credential::class, 'routeros_credential_id');
    }

    /** The remote agent that polls this device, or null when polled centrally. */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /** The upstream device this one depends on (drives the hierarchy + inspector). */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_device_id');
    }

    /** @return HasMany<Device, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_device_id');
    }

    public function interfaces(): HasMany
    {
        return $this->hasMany(NetworkInterface::class);
    }
}
