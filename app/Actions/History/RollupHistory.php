<?php

namespace App\Actions\History;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Fill the 5m and 1h rollup tiers from raw samples (GitHub #28).
 *
 * Idempotent: a slice is recomputed from its source and upserted on (keys, bucket) with DO
 * UPDATE replacing the row, never adding to it, so re-running any period gives the same rows.
 * Only closed buckets are rolled (a bucket closes once it's `rollup.grace` seconds in the past,
 * which covers a poll whose rows land a little after their ts), and each (family, tier) keeps a
 * watermark in history_rollup_state that moves forward in the same transaction as the upsert.
 *
 * Bounded: work goes in fixed time slices (one INSERT ... SELECT each, its own short
 * transaction, reading raw by the ts BRIN so it never scans a whole day), round-robin across
 * every family and tier, until caught up or the run's time budget is spent. A run after downtime
 * just picks up from the watermark and the next runs carry on, and the first run on an existing
 * install starts at the oldest raw partition, which is the backfill. The raw tables only ever
 * see plain SELECTs from here, so the pollers are never blocked.
 *
 * Sources: 5m reads raw. 1h reads the 5m tier where 5m still holds the slice (12x fewer rows)
 * and raw where it doesn't, eg a Dude import that dropped a year of history into raw.
 */
class RollupHistory
{
    /** date_bin origin for every rollup bucket. Any midnight works, it just has to never change. */
    public const ORIGIN = '2000-01-01 00:00:00';

    /** @var array<string, array<string, Carbon>> watermarks, loaded once per run and kept current */
    private array $marks = [];

    /** @var array<string, ?Carbon> oldest partition per source table, per run */
    private array $oldest = [];

    /** @var array<string, true> rollup partitions already known to exist this run */
    private array $ensured = [];

    public function __construct(
        private readonly HistoryTiers $tiers,
        private readonly ManageHistoryPartitions $partitions,
    ) {}

    /**
     * @param  float|null  $budget  seconds to spend, null = config default, 0 = until caught up
     * @return array{slices:int, caught_up:bool}
     */
    public function __invoke(?float $budget = null): array
    {
        $budget ??= (float) config('mymate.history.rollup.budget', 240);
        $deadline = $budget > 0 ? microtime(true) + $budget : INF;
        $this->marks = $this->tiers->watermarks();
        $this->oldest = [];
        $this->ensured = [];

        $pending = [];
        foreach (array_keys(HistoryTiers::ROLLUPS) as $tier) {
            foreach (array_keys(HistoryFamilies::FAMILIES) as $family) {
                $pending["{$family}:{$tier}"] = [$family, $tier];
            }
        }

        // One slice per (family, tier) per pass so one busy family can't starve the rest while a
        // backfill is running. Finer tier first in each pass so 1h has fresh 5m rows to read.
        // Always at least one pass, so even a tiny budget makes progress.
        $slices = 0;
        do {
            foreach ($pending as $i => [$family, $tier]) {
                if ($this->step($family, $tier)) {
                    $slices++;
                } elseif ($tier === '5m' || ! isset($pending["{$family}:5m"])) {
                    // 1h waiting on 5m isn't done, it just can't move until 5m does
                    unset($pending[$i]);
                }
                if (microtime(true) >= $deadline) {
                    break;
                }
            }
        } while ($pending !== [] && microtime(true) < $deadline);

        return ['slices' => $slices, 'caught_up' => $pending === []];
    }

    /**
     * Forget where the given families (default all) got to, so the next run recomputes them from
     * the oldest data still around. For when raw history was rewritten underneath the rollups:
     * a Dude import, the demo reseeding. Rows already in the tiers stay put and are replaced as
     * the job passes over them again.
     *
     * @param  list<string>|null  $families
     */
    public static function rewind(?array $families = null): void
    {
        $q = DB::table('history_rollup_state');
        if ($families !== null) {
            $q->whereIn('family', $families);
        }
        $q->delete();
    }

    /** Roll up the next slice for one family/tier. False when there's nothing closed left to do. */
    private function step(string $family, string $tier): bool
    {
        $spec = HistoryFamilies::get($family);
        $step = HistoryTiers::step($tier);
        $grace = max(0, (int) config('mymate.history.rollup.grace', 300));
        $closed = HistoryTiers::floorTo(now()->subSeconds($grace), $step);
        $marks = $this->marks[$family] ?? [];

        if ($tier === '5m') {
            $oldest = $this->oldestPartition($spec['raw']);
            if ($oldest === null) {
                return false;
            }
            // no point writing 5m rows the partition job would drop straight away
            $floor = $oldest->max($this->tiers->cutoff('5m'));
            $start = isset($marks[$tier]) ? $marks[$tier]->max($floor) : $floor;
            $end = $start->copy()->addSeconds((int) config('mymate.history.rollup.slice_5m', 3600))->min($closed);
            if ($end->lessThanOrEqualTo($start)) {
                return false;
            }
            $this->rollFromRaw($family, $tier, $start, $end);

            return true;
        }

        $oldest = collect([
            $this->oldestPartition($spec['raw']),
            $this->oldestPartition(HistoryFamilies::rollupTable($family, '5m')),
        ])->filter()->sort()->first();
        if ($oldest === null) {
            return false;
        }
        $floor = $oldest->max($this->tiers->cutoff($tier));
        $start = isset($marks[$tier]) ? $marks[$tier]->max($floor) : $floor;
        $end = $start->copy()->addSeconds((int) config('mymate.history.rollup.slice_1h', 86400))->min($closed);

        $fineCutoff = $this->tiers->cutoff('5m');
        $fineMark = $marks['5m'] ?? null;
        if ($fineMark !== null && $start->greaterThanOrEqualTo($fineCutoff)) {
            // 5m holds this stretch: read it, but only as far as it's been rolled itself
            $end = $end->min(HistoryTiers::floorTo($fineMark, $step));
            if ($end->lessThanOrEqualTo($start)) {
                return false;
            }
            $this->rollFromRollup($family, '5m', $tier, $start, $end);
        } else {
            // older than 5m keeps (or 5m hasn't started yet), go to raw, up to where 5m takes over
            if ($fineMark !== null && $fineCutoff->greaterThan($start)) {
                $end = $end->min($fineCutoff);
            }
            if ($end->lessThanOrEqualTo($start)) {
                return false;
            }
            $this->rollFromRaw($family, $tier, $start, $end);
        }

        return true;
    }

    private function oldestPartition(string $table): ?Carbon
    {
        if (! array_key_exists($table, $this->oldest)) {
            $this->oldest[$table] = $this->partitions->oldestPartitionStart($table);
        }

        return $this->oldest[$table]?->copy();
    }

    private function ensurePartition(string $table, string $grain, Carbon $at): void
    {
        $key = $table.'_'.$at->format($grain === 'month' ? 'Ym' : 'Ymd');
        if (! isset($this->ensured[$key])) {
            $this->partitions->ensure($table, $grain, $at);
            $this->ensured[$key] = true;
        }
    }

    /** INSERT ... SELECT the [start, end) slice of raw samples into $tier. */
    private function rollFromRaw(string $family, string $tier, Carbon $start, Carbon $end): void
    {
        $spec = HistoryFamilies::get($family);
        $select = [];
        foreach ($spec['metrics'] as $metric => $extra) {
            $expr = HistoryFamilies::rawExpr($family, $metric);
            $select[] = "sum({$expr})";
            $select[] = "count({$expr})";
            foreach ($extra as $agg) {
                $select[] = "{$agg}({$expr})";
            }
        }

        $this->upsert($family, $tier, $spec['raw'], 'ts', $select, $start, $end);
    }

    /** Re-aggregate a finer rollup tier into a coarser one: sums and counts add, max of maxes. */
    private function rollFromRollup(string $family, string $fromTier, string $tier, Carbon $start, Carbon $end): void
    {
        $select = [];
        foreach (HistoryFamilies::get($family)['metrics'] as $metric => $extra) {
            $select[] = "sum({$metric}_sum)";
            $select[] = "sum({$metric}_cnt)";
            foreach ($extra as $agg) {
                $select[] = "{$agg}({$metric}_{$agg})";
            }
        }

        $this->upsert($family, $tier, HistoryFamilies::rollupTable($family, $fromTier), 'bucket', $select, $start, $end);
    }

    /** @param list<string> $select aggregate expressions, in HistoryFamilies::rollupColumns order */
    private function upsert(string $family, string $tier, string $source, string $tsCol, array $select, Carbon $start, Carbon $end): void
    {
        $spec = HistoryFamilies::get($family);
        $table = HistoryFamilies::rollupTable($family, $tier);
        $keys = implode(', ', $spec['keys']);
        $cols = HistoryFamilies::rollupColumns($family);
        $update = implode(', ', array_map(static fn ($c) => "{$c} = EXCLUDED.{$c}", $cols));
        $step = HistoryTiers::step($tier);
        $grain = HistoryTiers::ROLLUPS[$tier]['grain'];
        // group by the date_bin by position, the word "bucket" would bind to the source column
        $bucketPos = count($spec['keys']) + 1;

        // the slice can straddle a midnight / month end, make sure both sides have a partition
        $this->ensurePartition($table, $grain, $start);
        $this->ensurePartition($table, $grain, $end->copy()->subSecond());

        $sql = "INSERT INTO {$table} ({$keys}, bucket, ".implode(', ', $cols).")
                SELECT {$keys}, date_bin('{$step} seconds'::interval, {$tsCol}, '".self::ORIGIN."'::timestamp), ".implode(', ', $select)."
                FROM {$source}
                WHERE {$tsCol} >= ?::timestamp AND {$tsCol} < ?::timestamp
                GROUP BY {$keys}, {$bucketPos}
                ON CONFLICT ({$keys}, bucket) DO UPDATE SET {$update}";

        DB::transaction(function () use ($sql, $family, $tier, $start, $end): void {
            DB::statement($sql, [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')]);
            DB::statement(
                'INSERT INTO history_rollup_state (family, tier, rolled_to, updated_at) VALUES (?, ?, ?, ?)
                 ON CONFLICT (family, tier) DO UPDATE SET rolled_to = EXCLUDED.rolled_to, updated_at = EXCLUDED.updated_at',
                [$family, $tier, $end->format('Y-m-d H:i:s'), now()->format('Y-m-d H:i:s')],
            );
        });
        $this->marks[$family][$tier] = $end->copy();
    }
}
