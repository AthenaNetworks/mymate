<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * BRIN index on ts for every raw samples table (GitHub #28). The rollup job reads a raw
 * time slice across every key ("all interfaces, 12:00 to 13:00"), which the existing
 * (key, ts) btrees can't serve, so without this each slice would seq scan the whole day's
 * partition. Samples are appended in time order so a BRIN is a near perfect fit and tiny.
 *
 * Built without blocking the pollers: the index goes on the parent ONLY (so it starts out
 * invalid), each existing partition gets its own CREATE INDEX CONCURRENTLY and is attached,
 * and once every partition is attached postgres marks the parent index valid. New partitions
 * pick it up automatically. CONCURRENTLY can't run inside a transaction, hence the opt out.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const TABLES = ['interface_samples', 'device_metric_samples', 'ping_samples', 'sensor_samples', 'probe_samples'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            $parentIdx = "{$table}_ts_brin";
            DB::statement("CREATE INDEX IF NOT EXISTS {$parentIdx} ON ONLY {$table} USING brin (ts)");

            foreach ($this->partitions($table) as $partition) {
                $idx = "{$partition}_ts_brin";
                DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS \"{$idx}\" ON \"{$partition}\" USING brin (ts)");
                if (! $this->isAttached($idx)) {
                    DB::statement("ALTER INDEX {$parentIdx} ATTACH PARTITION \"{$idx}\"");
                }
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            // dropping the parent index takes every attached partition index with it
            DB::statement("DROP INDEX IF EXISTS {$table}_ts_brin");
        }
    }

    /** @return list<string> */
    private function partitions(string $table): array
    {
        return array_map(static fn ($r): string => $r->name, DB::select(<<<'SQL'
            SELECT c.relname AS name
            FROM pg_inherits i
            JOIN pg_class c ON c.oid = i.inhrelid
            JOIN pg_class p ON p.oid = i.inhparent
            WHERE p.relname = ?
        SQL, [$table]));
    }

    private function isAttached(string $index): bool
    {
        return DB::selectOne(<<<'SQL'
            SELECT 1 AS ok FROM pg_inherits i JOIN pg_class c ON c.oid = i.inhrelid WHERE c.relname = ?
        SQL, [$index]) !== null;
    }
};
