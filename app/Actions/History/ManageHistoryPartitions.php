<?php

namespace App\Actions\History;

use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Roll the daily history partitions forward and drop expired ones for every
 * RANGE-partitioned samples table (interface_samples + device_metric_samples).
 * Idempotent - safe to run repeatedly (loop cadence, scheduler, or
 * `mymate:loop --partitions`). Retention = drop partitions whose day is older than
 * `history.retention_days`.
 */
class ManageHistoryPartitions
{
    /** Parent tables that are daily-partitioned; each partition is "{table}_YYYYMMDD". */
    private const TABLES = ['interface_samples', 'device_metric_samples', 'ping_samples'];

    /** @return array{created:int, dropped:int} */
    public function __invoke(): array
    {
        $ahead = max(0, (int) config('mymate.history.partitions_ahead', 3));
        // Retention is operator-editable - read the live Settings value.
        $retentionDays = max(1, app(Settings::class)->getInt('history.retention_days', 14));
        $cutoff = now()->startOfDay()->subDays($retentionDays);

        $created = 0;
        $dropped = 0;
        foreach (self::TABLES as $table) {
            // Ensure [yesterday .. today+ahead] exist (yesterday covers writes that land
            // just after a UTC-midnight rollover).
            for ($i = -1; $i <= $ahead; $i++) {
                if ($this->ensureDailyPartition($table, now()->startOfDay()->addDays($i))) {
                    $created++;
                }
            }
            $dropped += $this->dropPartitionsBefore($table, $cutoff);
        }

        return ['created' => $created, 'dropped' => $dropped];
    }

    /** Create the daily partition for $day if absent. Returns true if it created one. */
    private function ensureDailyPartition(string $table, Carbon $day): bool
    {
        $name = $table.'_'.$day->format('Ymd');
        if (Schema::hasTable($name)) {
            return false;
        }

        $from = $day->format('Y-m-d 00:00:00');
        $to = $day->copy()->addDay()->format('Y-m-d 00:00:00');

        DB::statement(
            "CREATE TABLE IF NOT EXISTS \"{$name}\" PARTITION OF {$table} FOR VALUES FROM ('{$from}') TO ('{$to}')"
        );

        return true;
    }

    /** Drop every daily partition of $table whose day is strictly before $cutoffDay. */
    private function dropPartitionsBefore(string $table, Carbon $cutoffDay): int
    {
        $cutoff = (int) $cutoffDay->format('Ymd');
        $prefixLen = strlen($table) + 1; // "{table}_"
        $dropped = 0;

        foreach ($this->partitionNames($table) as $name) {
            $datePart = substr($name, $prefixLen);
            if (strlen($datePart) !== 8 || ! ctype_digit($datePart)) {
                continue; // not a YYYYMMDD daily partition - leave it alone
            }
            if ((int) $datePart < $cutoff) {
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
