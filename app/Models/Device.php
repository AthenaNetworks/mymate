<?php

namespace App\Models;

use App\Enums\BackupStatus;
use App\Enums\DeviceStatus;
use App\Enums\DeviceType;
use App\Enums\PollMethod;
use App\Enums\UpgradeStatus;
use App\Support\Visibility;
use Database\Factories\DeviceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Device extends Model
{
    /** @use HasFactory<DeviceFactory> */
    use HasFactory;

    /** Restricted operators (GitHub #28) only see devices on maps they're granted. */
    protected static function booted(): void
    {
        static::addGlobalScope('visibility', function (Builder $query): void {
            if ($user = Visibility::restrictedUser()) {
                $query->whereIn($query->getModel()->getTable().'.id', $user->visibleDeviceIds());
            }
        });
    }

    protected $fillable = [
        'name', 'mgmt_ip', 'ping_source', 'poll_method', 'credential_id', 'ssh_credential_id', 'routeros_credential_id', 'agent_id',
        'status', 'monitored', 'last_change', 'fail_streak', 'map_x', 'map_y', 'latitude', 'longitude', 'geo_source', 'snmp_latitude', 'snmp_longitude',
        'site_id', 'site_source',
        'device_type', 'icon', 'icon_color', 'parent_device_id', 'vendor', 'model', 'serial', 'cpu', 'ram_bytes', 'arch', 'uptime_seconds', 'uptime_at',
        'os_version', 'latest_version', 'upgrade_status', 'upgrade_message', 'upgrade_at',
        'discovery_error', 'discovered_at',
        'cpu_pct', 'mem_used_pct', 'temp_c', 'metrics_at', 'cpu_loads',
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
        'snmp_latitude' => 'float',
        'snmp_longitude' => 'float',
        'uptime_seconds' => 'integer',
        'ram_bytes' => 'integer',
        'uptime_at' => 'datetime',
        'cpu_pct' => 'float',
        'mem_used_pct' => 'float',
        'temp_c' => 'float',
        'metrics_at' => 'datetime',
        // [{"index": 196608, "load_pct": 12.0}, ...] latest per-processor load
        'cpu_loads' => 'array',
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

    /**
     * Devices the engine should actually touch: monitored AND with a management IP. A device with
     * no IP is a static map object (a dumb switch, a patch panel, an upstream you can't reach) - it
     * exists to be drawn and linked to, and must never be handed to a pinger, poller, prober or
     * backup/upgrade job (GitHub #9 / #28 / #49). Every place that selects devices for work goes
     * through this, so the rule lives in one spot rather than as null checks at each call site.
     */
    public function scopePollable(Builder $query): Builder
    {
        return $query->where('monitored', true)->whereNotNull('mgmt_ip');
    }

    /**
     * Whether a device counts toward the live picture (header counts, geo feed, live count
     * patches). Normally that's just `monitored`, a paused device is left out. The sales demo is
     * the exception: its devices are unmonitored on purpose so no real poller touches them while
     * the simulator animates them, and they still need to show up as a live network.
     */
    public static function countsAsLive(bool $monitored): bool
    {
        return $monitored || (bool) config('mymate.demo.enabled');
    }

    /** A static map object: no management IP, so never polled (see scopePollable). */
    public function isStatic(): bool
    {
        return $this->mgmt_ip === null || $this->mgmt_ip === '';
    }

    /**
     * Devices polled from the same place: one agent's, or the central server's (agent_id null).
     * A management IP only has to be unique within this scope (GitHub #49) - two sites behind two
     * agents can reuse the same private subnet.
     */
    public function scopeInPollScope(Builder $query, ?int $agentId): Builder
    {
        return $agentId === null ? $query->whereNull('agent_id') : $query->where('agent_id', $agentId);
    }

    /**
     * The existing device an importer should update for $ip. Importers (Dude, LibreNMS) bring in
     * one flat list with no agent context, so when the same IP now exists in several poll scopes
     * prefer the central one, deterministically, rather than whichever row the DB returns first.
     */
    public static function matchForImport(string $ip): ?self
    {
        return static::withoutGlobalScope('visibility')
            ->where('mgmt_ip', $ip)
            ->orderByRaw('agent_id IS NOT NULL') // central (false) first
            ->orderBy('id')
            ->first();
    }

    /**
     * The device already using $ip in the given poll scope, if any (ignoring $ignoreId, the device
     * being edited). Bypasses the restricted-operator visibility scope on purpose: a clash with a
     * device the operator can't see must still be caught here, not surface as a unique-index 500.
     */
    public static function ipConflict(string $ip, ?int $agentId, ?int $ignoreId = null): ?self
    {
        return static::withoutGlobalScope('visibility')
            ->inPollScope($agentId)
            ->where('mgmt_ip', $ip)
            ->when($ignoreId !== null, fn (Builder $q) => $q->whereKeyNot($ignoreId))
            ->first(['id', 'name', 'mgmt_ip', 'agent_id']);
    }

    /**
     * The physical location this device sits at, or null when it isn't assigned to one.
     *
     * The site's coordinates reach the geo map through DeviceGeo::resolve at read time rather
     * than being copied into the device columns on assignment. Writing the site's coordinates
     * onto every device would make a site that moves (a corrected survey, a rebuild) leave
     * thousands of devices behind at the old position, and would make "has this device been
     * placed itself?" unanswerable.
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
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

    /** Disks / memory entries as of the last metrics poll (device page). */
    public function storages(): HasMany
    {
        return $this->hasMany(DeviceStorage::class);
    }

    /** Service probes (HTTP/TCP) attached to this device (GitHub #19). */
    public function probes(): HasMany
    {
        return $this->hasMany(Probe::class);
    }

    /** Firmware upgrade attempts, one row each (Events tab). */
    public function upgrades(): HasMany
    {
        return $this->hasMany(DeviceUpgrade::class);
    }

    /** Every map this device is placed on (one row per map). None = hidden from all maps. */
    public function mapPositions(): HasMany
    {
        return $this->hasMany(DeviceMapPosition::class);
    }
}
