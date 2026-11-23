<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A backhaul link between two sites (see the create_site_links_table migration). Imported from
 * the OSS that owns the backbone topology so the geo map draws real tower-to-tower links.
 *
 * Since GitHub #85 a link may also be bound to the interfaces that carry the circuit, so its
 * live state, throughput and capacity can be derived (and, once liveness is persisted, so a
 * failover to the backup is visible). `role` records where the circuit is *meant* to be; it is
 * never rewritten by what the pollers see.
 */
class SiteLink extends Model
{
    /** Intended role of the circuit in the backbone - documentation, not a live fact. */
    public const ROLES = ['primary', 'backup', 'unknown'];

    /** Where a binding came from. `import` = the OSS pair, with no interface binding. */
    public const BINDING_SOURCES = ['import', 'manual', 'lldp', 'cdp', 'fdb', 'ospf', 'bps'];

    protected $fillable = [
        'site_a_id', 'site_b_id', 'media_type', 'external_ref',
        'a_interface_id', 'b_interface_id', 'role',
        'capacity_ab_mbps', 'capacity_ba_mbps',
        'binding_source', 'binding_confidence', 'verified_at',
    ];

    protected $casts = [
        'capacity_ab_mbps' => 'integer',
        'capacity_ba_mbps' => 'integer',
        'binding_confidence' => 'integer',
        'verified_at' => 'datetime',
    ];

    public function siteA(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_a_id');
    }

    public function siteB(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_b_id');
    }

    public function aInterface(): BelongsTo
    {
        return $this->belongsTo(NetworkInterface::class, 'a_interface_id');
    }

    public function bInterface(): BelongsTo
    {
        return $this->belongsTo(NetworkInterface::class, 'b_interface_id');
    }

    /**
     * A->B effective capacity (Mbps): the link's own override, else the slower of the two bound
     * interfaces, else the default circuit speed when at least one end is bound. Null when
     * neither end is bound - there is no throughput to scale, so the map keeps it neutral.
     * Same rules as {@see Link::effAbMbps()} for device links.
     */
    public function effCapacityAbMbps(): ?int
    {
        return $this->capacity_ab_mbps ?? $this->capacityFromEnds();
    }

    /** B->A effective capacity (Mbps): override, else A->B (symmetric fallback). */
    public function effCapacityBaMbps(): ?int
    {
        return $this->capacity_ba_mbps ?? $this->capacityFromEnds();
    }

    private function capacityFromEnds(): ?int
    {
        $speeds = array_filter(
            [$this->aInterface?->speed_mbps, $this->bInterface?->speed_mbps],
            fn (?int $s): bool => $s !== null && $s > 0,
        );

        if ($speeds !== []) {
            return (int) min($speeds);
        }

        // At least one real interface but no known speed -> assume the default circuit speed.
        if ($this->aInterface !== null || $this->bInterface !== null) {
            return (int) config('mymate.links.default_speed_mbps', 1000);
        }

        return null;
    }
}
