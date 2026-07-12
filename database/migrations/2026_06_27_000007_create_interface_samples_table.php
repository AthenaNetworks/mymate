<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Recent history: a native-PostgreSQL RANGE-partitioned append table for
 * per-interface throughput samples. Additive - the live fast path stays on
 * interfaces.util_*; this is the ~7-30 day trend window, with retention by dropping
 * old daily partitions (see App\Actions\History\ManageHistoryPartitions).
 *
 * Laravel's schema builder can't express PARTITION BY, so the DDL is raw. No FK on
 * interface_id (this is a high-volume, append-only, drop-by-partition table - an FK
 * would slow bulk inserts and complicate drops); orphans age out with retention.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE interface_samples (
                interface_id bigint NOT NULL,
                ts timestamp(0) without time zone NOT NULL,
                bps_in double precision,
                bps_out double precision,
                util_in double precision,
                util_out double precision
            ) PARTITION BY RANGE (ts)
        SQL);

        // Partition pruning + this index serve "interface X over [from,to]" queries.
        DB::statement('CREATE INDEX interface_samples_iface_ts_idx ON interface_samples (interface_id, ts)');

        // Seed daily partitions for [yesterday .. +3 days] so writes work immediately
        // after migrate; ManageHistoryPartitions keeps rolling them forward + drops old.
        $day = now()->startOfDay()->subDay();
        for ($i = 0; $i < 5; $i++) {
            $this->createDailyPartition($day->copy()->addDays($i)->format('Y-m-d'));
        }
    }

    public function down(): void
    {
        // CASCADE drops every attached daily partition with the parent.
        DB::statement('DROP TABLE IF EXISTS interface_samples CASCADE');
    }

    private function createDailyPartition(string $date): void
    {
        $name = 'interface_samples_'.str_replace('-', '', $date);
        $from = $date.' 00:00:00';
        $to = date('Y-m-d', strtotime($date.' +1 day')).' 00:00:00';

        DB::statement(
            "CREATE TABLE IF NOT EXISTS \"{$name}\" PARTITION OF interface_samples FOR VALUES FROM ('{$from}') TO ('{$to}')"
        );
    }
};
