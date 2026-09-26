<?php

namespace App\Actions\History;

use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Roll the history partitions forward and drop expired ones, for the daily-partitioned raw
 * samples tables and for the rollup tiers (GitHub #28): 5m rollups are daily partitions too,
 * 1h rollups monthly. Idempotent - safe to run repeatedly (loop cadence, scheduler, or
 * `mymate:loop --partitions`). Each tier has its own retention, see HistoryTiers.
 */
class ManageHistoryPartitions
{
    /** Parent tables that are daily-partitioned; each partition is "{table}_YYYYMMDD". */
    private const TABLES = ['interface_samples', 'device_metric_samples', 'ping_samples', 'sensor_samples', 'probe_samples'];

    /** @return array{created:int, dropped:int} */
    public function __invoke(): array
    {
        $ahead = max(0, (int) config('mymate.history.partitions_ahead', 3));
        // Retention is operator-editable - read the live Settings value.
        $retentionDays = max(1, app(Settings::class)->getInt('history.retention_days', 14));
        $cutoff = now()->startOfDay()->subDays($retentionDays);
        $tiers = app(HistoryTiers::class);

        $created = 0;
        $dropped = 0;
        foreach (self::TABLES as $table) {
            // Ensure [yesterday .. today+ahead] exist (yesterday covers writes that land
            // just after a UTC-midnight rollover).
            for ($i = -1; $i <= $ahead; $i++) {
                if ($this->ensure($table, 'day', now()->startOfDay()->addDays($i))) {
                    $created++;
                }
            }
            $dropped += $this->dropPartitionsBefore($table, 'day', $cutoff);
        }

        foreach (array_keys(HistoryFamilies::FAMILIES) as $family) {
            foreach (HistoryTiers::ROLLUPS as $tier => $meta) {
                $table = HistoryFamilies::rollupTable($family, $tier);
                if ($meta['grain'] === 'day') {
                    for ($i = -1; $i <= $ahead; $i++) {
                        $created += (int) $this->ensure($table, 'day', now()->startOfDay()->addDays($i));
                    }
                } else {
                    // this month and next, so the rollover at month end never finds a gap
                    $created += (int) $this->ensure($table, 'month', now()->startOfMonth());
                    $created += (int) $this->ensure($table, 'month', now()->startOfMonth()->addMonthNoOverflow());
                }
                $dropped += $this->dropPartitionsBefore($table, $meta['grain'], $tiers->cutoff($tier));
            }
        }

        return ['created' => $created, 'dropped' => $dropped];
    }

    /**
     * Create the partition of $table holding $at if it's absent ("{table}_YYYYMMDD" for a day
     * grain, "{table}_YYYYMM" for a month). Returns true if it created one.
     */
    public function ensure(string $table, string $grain, Carbon $at): bool
    {
        $start = $grain === 'month' ? $at->copy()->startOfMonth() : $at->copy()->startOfDay();
        $end = $grain === 'month' ? $start->copy()->addMonthNoOverflow() : $start->copy()->addDay();
        $name = $table.'_'.$start->format($grain === 'month' ? 'Ym' : 'Ymd');
        if (Schema::hasTable($name)) {
            return false;
        }

        $from = $start->format('Y-m-d 00:00:00');
        $to = $end->format('Y-m-d 00:00:00');

        DB::statement(
            "CREATE TABLE IF NOT EXISTS \"{$name}\" PARTITION OF {$table} FOR VALUES FROM ('{$from}') TO ('{$to}')"
        );

        return true;
    }

    /** Start of the oldest partition $table has, or null when it has none. */
    public function oldestPartitionStart(string $table): ?Carbon
    {
        $prefixLen = strlen($table) + 1;
        $oldest = null;
        foreach ($this->partitionNames($table) as $name) {
            $part = substr($name, $prefixLen);
            if (! ctype_digit($part) || (strlen($part) !== 8 && strlen($part) !== 6)) {
                continue;
            }
            $start = Carbon::createFromFormat(strlen($part) === 8 ? '!Ymd' : '!Ym', $part);
            if ($oldest === null || $start->lessThan($oldest)) {
                $oldest = $start;
            }
        }

        return $oldest;
    }

    /**
     * Drop every partition of $table that's wholly before $cutoff: a daily one whose day is
     * strictly before the cutoff day, a monthly one whose month is strictly before the cutoff's.
     */
    private function dropPartitionsBefore(string $table, string $grain, Carbon $cutoff): int
    {
        $len = $grain === 'month' ? 6 : 8;
        $limit = (int) $cutoff->format($grain === 'month' ? 'Ym' : 'Ymd');
        $prefixLen = strlen($table) + 1; // "{table}_"
        $dropped = 0;

        foreach ($this->partitionNames($table) as $name) {
            $datePart = substr($name, $prefixLen);
            if (strlen($datePart) !== $len || ! ctype_digit($datePart)) {
                continue; // not one of our partitions - leave it alone
            }
            if ((int) $datePart < $limit) {
                DB::statement("DROP TABLE IF EXISTS \"{$name}\"");
                $dropped++;
            }
        }

        return $dropped;
    }

    /** @return list<string> child partition table names of $table */
    private function partitionNames(string $table): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT c.relname AS name
            FROM pg_inherits i
            JOIN pg_class c ON c.oid = i.inhrelid
            JOIN pg_class p ON p.oid = i.inhparent
            WHERE p.relname = ?
        SQL, [$table]);

        return array_map(static fn ($r): string => $r->name, $rows);
    }
}
