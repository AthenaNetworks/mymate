<?php

namespace App\Support;

use App\Enums\DeviceStatus;
use App\Models\NetworkInterface;
use App\Models\SiteLink;
use Illuminate\Support\Carbon;

/**
 * Derives a site link's live state from what the pollers already wrote on its two interfaces
 * (oper status, throughput, sent/received rates) and the state of the routers at each end
 * (GitHub #85).
 *
 * Deliberately read-time and side-effect free: Phase 1 computes it on every read, Phase 3 will
 * persist transitions (for hysteresis and "failed over 14 minutes ago") without changing the
 * rules here. Nothing in this class writes, and nothing treats a missing sample as healthy.
 */
class SiteLinkLiveness
{
    public const UP = 'up';

    public const DEGRADED = 'degraded';

    public const DOWN = 'down';

    public const UNKNOWN = 'unknown';

    /**
     * @return array{state:string, reason:string, bps_ab:?int, bps_ba:?int, util_ab:?float,
     *               util_ba:?float, util:?float, capacity_ab_mbps:?int, capacity_ba_mbps:?int,
     *               sampled_at:?string}
     */
    public static function evaluate(SiteLink $link, ?Carbon $now = null): array
    {
        $now ??= now();
        $a = $link->aInterface;
        $b = $link->bInterface;
        $capAb = $link->effCapacityAbMbps();
        $capBa = $link->effCapacityBaMbps();

        if ($a === null && $b === null) {
            // No bound ends: the pair is still real topology, but nothing about it is measurable.
            return ['state' => self::UNKNOWN, 'reason' => 'unbound']
                + self::numbers(null, null, $capAb, $capBa, null);
        }

        // A router that is down takes its end with it, whatever the last counters said.
        if (self::deviceDown($a) || self::deviceDown($b)) {
            return ['state' => self::DOWN, 'reason' => 'device_down']
                + self::numbers($a, $b, $capAb, $capBa, self::latestSample($a, $b));
        }

        if (($a !== null && $a->oper_status === 'down') || ($b !== null && $b->oper_status === 'down')) {
            return ['state' => self::DOWN, 'reason' => 'oper_down']
                + self::numbers($a, $b, $capAb, $capBa, self::latestSample($a, $b));
        }

        // A sample only counts while it is fresh. A backhaul whose poller stopped is `unknown`,
        // not `up` - the whole point is that a stale line must never read green.
        $sampledAt = self::latestSample($a, $b);
        $staleAfter = max(1, (int) config('mymate.poll.interval', 12))
            * max(1, (int) config('mymate.site_links.liveness.stale_after', 3));

        if ($sampledAt === null || $sampledAt->diffInSeconds($now, true) > $staleAfter) {
            return ['state' => self::UNKNOWN, 'reason' => 'no_data']
                + self::numbers($a, $b, $capAb, $capBa, $sampledAt);
        }

        $live = self::numbers($a, $b, $capAb, $capBa, $sampledAt);
        $degradedUtil = (float) config('mymate.site_links.liveness.degraded_util', 85);
        $errorRate = (float) config('mymate.site_links.liveness.degraded_errors_per_s', 5);
        $errors = max(array_map(
            static fn (?float $v): float => $v ?? 0.0,
            [$a?->errors_in, $a?->errors_out, $b?->errors_in, $b?->errors_out],
        ));

        if ($live['util'] !== null && $live['util'] >= $degradedUtil) {
            return ['state' => self::DEGRADED, 'reason' => 'saturated'] + $live;
        }

        if ($errors >= $errorRate) {
            return ['state' => self::DEGRADED, 'reason' => 'errors'] + $live;
        }

        return ['state' => self::UP, 'reason' => 'ok'] + $live;
    }

    /**
     * Throughput and utilisation per direction. A->B is traffic leaving A (`bps_out`), which is
     * also what B sees arriving (`bps_in`); taking the busier of the two is robust to one end not
     * reporting. Mirrors the client-side rule in `edgeData.ts` so the map and the API agree.
     *
     * @return array{bps_ab:?int, bps_ba:?int, util_ab:?float, util_ba:?float, util:?float,
     *               capacity_ab_mbps:?int, capacity_ba_mbps:?int, sampled_at:?string}
     */
    private static function numbers(?NetworkInterface $a, ?NetworkInterface $b, ?int $capAb, ?int $capBa, ?Carbon $sampledAt): array
    {
        $abBps = self::maxOrNull([$a?->bps_out, $b?->bps_in]);
        $baBps = self::maxOrNull([$b?->bps_out, $a?->bps_in]);

        $utilAb = self::utilPercent($abBps, $capAb);
        $utilBa = self::utilPercent($baBps, $capBa);
        $util = self::maxOrNull([$utilAb, $utilBa]);

        return [
            'bps_ab' => $abBps !== null ? (int) $abBps : null,
            'bps_ba' => $baBps !== null ? (int) $baBps : null,
            'util_ab' => $utilAb,
            'util_ba' => $utilBa,
            'util' => $util,
            'capacity_ab_mbps' => $capAb,
            'capacity_ba_mbps' => $capBa,
            'sampled_at' => $sampledAt?->toIso8601String(),
        ];
    }

    private static function utilPercent(?int $bps, ?int $capacityMbps): ?float
    {
        if ($bps === null || $capacityMbps === null || $capacityMbps <= 0) {
            return null;
        }

        return round(($bps / ($capacityMbps * 1_000_000)) * 100, 3);
    }

    private static function deviceDown(?NetworkInterface $interface): bool
    {
        $status = $interface?->device?->status;

        return $status === DeviceStatus::Down || $status === 'down';
    }

    private static function latestSample(?NetworkInterface $a, ?NetworkInterface $b): ?Carbon
    {
        $times = array_filter([$a?->last_ts, $b?->last_ts]);

        return $times === [] ? null : max($times);
    }

    /** @param  array<int, float|int|null|mixed>  $values */
    private static function maxOrNull(array $values): int|float|null
    {
        $nums = array_filter($values, static fn ($v): bool => $v !== null);
        if ($nums === []) {
            return null;
        }

        return max($nums);
    }
}
