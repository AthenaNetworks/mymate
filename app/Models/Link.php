<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Link extends Model
{
    protected $fillable = [
        'a_device_id', 'a_interface_id', 'b_device_id', 'b_interface_id',
        'bw_ab_mbps', 'bw_ba_mbps',
    ];

    protected $casts = [
        'bw_ab_mbps' => 'integer',
        'bw_ba_mbps' => 'integer',
    ];

    public function aDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'a_device_id');
    }

    public function bDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'b_device_id');
    }

    public function aInterface(): BelongsTo
    {
        return $this->belongsTo(NetworkInterface::class, 'a_interface_id');
    }

    public function bInterface(): BelongsTo
    {
        return $this->belongsTo(NetworkInterface::class, 'b_interface_id');
    }

    // --- Effective speed + utilisation -------------------
    // The single backend source of truth for a link's capacity + load. Capacity
    // lives on the link (the circuit), not the physical interface: an override if
    // set, else the slowest of the two end interfaces' SNMP speeds. Consumed by
    // LinkResource (map edge) and EvaluateAlerts.

    /** A->B effective speed (Mbps): override, else the slower of the two ends. Null when unknown. */
    public function effAbMbps(): ?int
    {
        if ($this->bw_ab_mbps !== null) {
            return $this->bw_ab_mbps;
        }

        $speeds = array_filter(
            [$this->aInterface?->speed_mbps, $this->bInterface?->speed_mbps],
            fn (?int $s): bool => $s !== null && $s > 0,
        );

        return $speeds === [] ? null : (int) min($speeds);
    }

    /** B->A effective speed (Mbps): override, else A->B (symmetric fallback). */
    public function effBaMbps(): ?int
    {
        return $this->bw_ba_mbps ?? $this->effAbMbps();
    }

    /** A->B utilisation % (traffic leaving A toward B), vs the A->B effective speed. */
    public function utilAb(): ?float
    {
        return $this->utilPercent($this->aInterface?->bps_out, $this->effAbMbps());
    }

    /** B->A utilisation % (traffic leaving B toward A), vs the B->A effective speed. */
    public function utilBa(): ?float
    {
        return $this->utilPercent($this->bInterface?->bps_out, $this->effBaMbps());
    }

    /** Higher of the two directions - the figure the edge colours by. Null when no speed. */
    public function util(): ?float
    {
        $vals = array_filter([$this->utilAb(), $this->utilBa()], fn (?float $v): bool => $v !== null);

        return $vals === [] ? null : max($vals);
    }

    /** Shared util formula (mirrors RateCalculator::utilPercent + linkColor.ts). */
    private function utilPercent(?int $bps, ?int $speedMbps): ?float
    {
        if ($bps === null || $speedMbps === null || $speedMbps <= 0) {
            return null;
        }

        return ($bps / ($speedMbps * 1_000_000)) * 100;
    }
}
