<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Long-term history (GitHub #28). Raw samples stay daily-partitioned with a short retention;
 * these rollup tables hold 5 minute and 1 hour aggregates of every graphed family for much
 * longer. See App\Actions\History\HistoryFamilies (the spec this mirrors) and RollupHistory
 * (the job that fills them).
 *
 * 5m tables are partitioned daily like the raw tables ("{table}_YYYYMMDD"), 1h tables monthly
 * ("{table}_YYYYMM"), so retention on either is still a cheap DROP TABLE. The primary key is
 * (keys, bucket): it's the ON CONFLICT target that makes a re-run replace rather than double
 * count, and it's also exactly the index the readers want. The 5m tier gets a BRIN on bucket
 * too, the hourly rollup reads the last hour out of today's 5m partition by bucket alone.
 *
 * The spec is copied in here on purpose, a migration shouldn't change shape if the app class
 * does later.
 */
return new class extends Migration
{
    private const FAMILIES = [
        'interface' => [['interface_id'], ['bps_in' => ['max'], 'bps_out' => ['max'], 'util_in' => ['max'], 'util_out' => ['max']]],
        'ping' => [['device_id'], ['rtt_ms' => ['max', 'min'], 'loss_pct' => ['max'], 'jitter_ms' => ['max']]],
        'sensor' => [['sensor_id', 'device_id'], ['value' => ['max', 'min']]],
        'probe' => [['probe_id'], ['latency_ms' => ['max', 'min'], 'up_pct' => ['min']]],
        'device_metric' => [['device_id'], [
            'cpu_pct' => ['max'], 'mem_used_pct' => ['max'], 'temp_c' => ['max', 'min'],
            'signal_dbm' => ['max', 'min'], 'snr_db' => ['max', 'min'], 'ccq_pct' => ['min'],
            'wireless_clients' => ['max'], 'ospf_neighbors' => ['min'],
        ]],
    ];

    public function up(): void
    {
        foreach (self::FAMILIES as $family => [$keys, $metrics]) {
            foreach (['5m', '1h'] as $tier) {
                $table = "{$family}_rollup_{$tier}";
                $cols = [];
                foreach ($keys as $k) {
                    $cols[] = "{$k} bigint NOT NULL";
                }
                $cols[] = 'bucket timestamp(0) without time zone NOT NULL';
                // doubles first then the int counts, keeps the row free of alignment padding
                $counts = [];
                foreach ($metrics as $m => $extra) {
                    $cols[] = "{$m}_sum double precision";
                    foreach ($extra as $agg) {
                        $cols[] = "{$m}_{$agg} double precision";
                    }
                    $counts[] = "{$m}_cnt integer NOT NULL DEFAULT 0";
                }
                $cols = array_merge($cols, $counts);
                $cols[] = 'PRIMARY KEY ('.implode(', ', [...$keys, 'bucket']).')';

                DB::statement("CREATE TABLE {$table} (\n    ".implode(",\n    ", $cols)."\n) PARTITION BY RANGE (bucket)");

                if ($tier === '5m') {
                    DB::statement("CREATE INDEX {$table}_bucket_brin ON {$table} USING brin (bucket)");
                    $day = now()->startOfDay()->subDay();
                    for ($i = 0; $i < 5; $i++) {
                        $d = $day->copy()->addDays($i);
                        $this->partition($table, $table.'_'.$d->format('Ymd'), $d->format('Y-m-d 00:00:00'), $d->copy()->addDay()->format('Y-m-d 00:00:00'));
                    }
                } else {
                    $month = now()->startOfMonth();
                    for ($i = 0; $i < 2; $i++) {
                        $m = $month->copy()->addMonthsNoOverflow($i);
                        $this->partition($table, $table.'_'.$m->format('Ym'), $m->format('Y-m-d 00:00:00'), $m->copy()->addMonthNoOverflow()->format('Y-m-d 00:00:00'));
                    }
                }
            }
        }

        // How far each (family, tier) has been rolled up. Every bucket before rolled_to is closed
        // and final, which is also what readers use to know where rollups end and raw takes over.
        DB::statement(<<<'SQL'
            CREATE TABLE history_rollup_state (
                family varchar(32) NOT NULL,
                tier varchar(8) NOT NULL,
                rolled_to timestamp(0) without time zone NOT NULL,
                updated_at timestamp(0) without time zone,
                PRIMARY KEY (family, tier)
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS history_rollup_state');
        foreach (array_keys(self::FAMILIES) as $family) {
            foreach (['5m', '1h'] as $tier) {
                DB::statement("DROP TABLE IF EXISTS {$family}_rollup_{$tier} CASCADE");
            }
        }
    }

    private function partition(string $parent, string $name, string $from, string $to): void
    {
        DB::statement("CREATE TABLE IF NOT EXISTS \"{$name}\" PARTITION OF {$parent} FOR VALUES FROM ('{$from}') TO ('{$to}')");
    }
};
