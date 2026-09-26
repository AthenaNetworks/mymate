<?php

namespace App\Actions\History;

use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The history storage tiers (GitHub #28): raw samples, then 5 minute and 1 hour rollups, each
 * with its own retention. Shared by the rollup job, partition maintenance and the readers so
 * they all agree on where a tier's data starts and ends.
 *
 *  - raw : every sample, daily partitions, `history.retention_days` (default 14)
 *  - 5m  : daily partitions, `history.rollup_5m_days` (default 30). Serves roughly 1 to 10 day
 *          windows and anything older than raw retention that's still inside it.
 *  - 1h  : monthly partitions, `history.rollup_1h_days` (default 400, a year plus a margin).
 *          Serves the long ranges, 240 points over a year is a 36h bucket so hourly is plenty.
 */
class HistoryTiers
{
    /** Rollup tiers, finest first. step = bucket width in seconds. */
    public const ROLLUPS = [
        '5m' => ['step' => 300, 'setting' => 'history.rollup_5m_days', 'default' => 30, 'grain' => 'day'],
        '1h' => ['step' => 3600, 'setting' => 'history.rollup_1h_days', 'default' => 400, 'grain' => 'month'],
    ];

    public function __construct(private readonly Settings $settings) {}

    /** Retention in days for 'raw', '5m' or '1h' (live Settings value). */
    public function retentionDays(string $tier): int
    {
        if ($tier === 'raw') {
            return max(1, $this->settings->getInt('history.retention_days', 14));
        }
        $t = self::ROLLUPS[$tier];

        return max(1, $this->settings->getInt($t['setting'], $t['default']));
    }

    /**
     * Oldest instant a tier is meant to hold. Same rule the partition drop uses (whole days
     * before today minus retention go), so a reader never plans on data that's been dropped.
     */
    public function cutoff(string $tier): Carbon
    {
        return now()->startOfDay()->subDays($this->retentionDays($tier));
    }

    /** The longest any tier keeps data, ie how far back a graph can usefully reach. */
    public function maxDays(): int
    {
        return max($this->retentionDays('raw'), $this->retentionDays('5m'), $this->retentionDays('1h'));
    }

    public static function step(string $tier): int
    {
        return $tier === 'raw' ? 1 : self::ROLLUPS[$tier]['step'];
    }

    /**
     * Pick the tier a window should be read from and the bucket width to use.
     *
     * The natural tier is the coarsest one whose step fits inside the bucket width the window
     * wants (span / max_points), a 24h graph wants 6 minute buckets so it reads 5m rollups, a
     * 30 day one wants 3h buckets so it reads hourly ones. If that tier no longer holds the
     * start of the window we step up to a coarser tier that does. On a rollup tier the bucket
     * width is rounded to a whole number of steps and the origin snapped to a step boundary, so
     * every rollup row lands wholly in one output bucket. On raw nothing changes from how the
     * graphs always bucketed.
     *
     * @return array{tier:string, bucketSeconds:int, origin:Carbon}
     */
    public function plan(Carbon $from, Carbon $to): array
    {
        $maxPoints = max(1, (int) config('mymate.history.max_points', 240));
        $span = max(1, $from->diffInSeconds($to));
        $desired = max(10, (int) ceil($span / $maxPoints));

        $order = ['raw', ...array_keys(self::ROLLUPS)];
        $idx = 0;
        foreach ($order as $i => $tier) {
            if (self::step($tier) <= $desired) {
                $idx = $i;
            }
        }
        while ($idx < count($order) - 1 && $from->lessThan($this->cutoff($order[$idx]))) {
            $idx++;
        }
        $tier = $order[$idx];

        if ($tier === 'raw') {
            return ['tier' => 'raw', 'bucketSeconds' => $desired, 'origin' => $from->copy()];
        }

        $step = self::step($tier);
        $bucketSeconds = $step * max(1, (int) round($desired / $step));

        return ['tier' => $tier, 'bucketSeconds' => $bucketSeconds, 'origin' => self::floorTo($from, $step)];
    }

    /**
     * Floor $at to a multiple of $step seconds on the wall clock the samples are stored in. The
     * ts columns are naive timestamps, and rollup buckets are date_bin'd from 2000-01-01, so
     * snap the naive value rather than the unix time (they only agree when the app runs UTC).
     */
    public static function floorTo(Carbon $at, int $step): Carbon
    {
        $naive = Carbon::parse($at->format('Y-m-d H:i:s'), 'UTC');
        $snapped = Carbon::createFromTimestampUTC(intdiv($naive->getTimestamp(), $step) * $step);

        return Carbon::parse($snapped->format('Y-m-d H:i:s'), $at->getTimezone());
    }

    /**
     * Rolled-up-to watermark per family and tier. Everything before it is final in that tier.
     *
     * @return array<string, array<string, Carbon>>
     */
    public function watermarks(): array
    {
        $out = [];
        foreach (DB::select('SELECT family, tier, rolled_to FROM history_rollup_state') as $r) {
            $out[$r->family][$r->tier] = Carbon::parse($r->rolled_to);
        }

        return $out;
    }
}
