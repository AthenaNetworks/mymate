<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * History the full device page needs that we didn't keep before: port packets / errors /
 * discards and oper status per interface, optical power, per-CPU load, storage (disks and
 * memory) and uptime. Everything plugs into the rollup machinery from GitHub #28, see
 * App\Actions\History\HistoryFamilies for the key and metric names.
 *
 * Safe on a big install: every change to an existing (and possibly huge, partitioned) table is
 * an ADD COLUMN that's either nullable with no default or has a constant default, which postgres
 * does as a catalog change only, no rewrite and no long lock. The rest is new tables.
 *
 * Specs are copied in here on purpose, a migration shouldn't change shape if the app class
 * does later.
 */
return new class extends Migration
{
    /** New metrics on existing families: family => [metric => extra aggregates]. */
    private const NEW_METRICS = [
        'interface' => [
            'pkts_in' => ['max'], 'pkts_out' => ['max'],
            'errors_in' => ['max'], 'errors_out' => ['max'],
            'discards_in' => ['max'], 'discards_out' => ['max'],
            'up_pct' => ['min'],
        ],
        'device_metric' => [
            'uptime_s' => ['max', 'min'],
        ],
    ];

    /** New families: family => [raw table, [key => type], [raw column => type], rollup metrics]. */
    private const NEW_FAMILIES = [
        'optical' => ['optical_samples', ['interface_id' => 'bigint'], ['rx_dbm' => 'double precision', 'tx_dbm' => 'double precision'], [
            'rx_dbm' => ['max', 'min'], 'tx_dbm' => ['max', 'min'],
        ]],
        'cpu' => ['cpu_samples', ['device_id' => 'bigint', 'cpu_index' => 'integer'], ['load_pct' => 'double precision'], [
            'load_pct' => ['max'],
        ]],
        'storage' => ['storage_samples', ['storage_id' => 'bigint', 'device_id' => 'bigint'], [
            'used_pct' => 'double precision', 'used_bytes' => 'double precision', 'size_bytes' => 'double precision',
        ], [
            'used_pct' => ['max'], 'used_bytes' => ['max'], 'size_bytes' => ['max'],
        ]],
    ];

    private const PORT_RATES = ['pkts_in', 'pkts_out', 'errors_in', 'errors_out', 'discards_in', 'discards_out'];

    public function up(): void
    {
        // Raw per-poll values on the existing sample tables. Rates are per second.
        foreach (self::PORT_RATES as $col) {
            DB::statement("ALTER TABLE interface_samples ADD COLUMN IF NOT EXISTS {$col} double precision");
        }
        DB::statement('ALTER TABLE interface_samples ADD COLUMN IF NOT EXISTS oper_up boolean');
        DB::statement('ALTER TABLE device_metric_samples ADD COLUMN IF NOT EXISTS uptime_s bigint');

        // Current values for the device page, and the raw counters the next delta is taken from.
        foreach (self::PORT_RATES as $col) {
            DB::statement("ALTER TABLE interfaces ADD COLUMN IF NOT EXISTS {$col} double precision");
        }
        DB::statement('ALTER TABLE interfaces ADD COLUMN IF NOT EXISTS port_counters jsonb');
        // Latest hrProcessorLoad per processor, [{"index": 196608, "load_pct": 12}, ...]
        DB::statement('ALTER TABLE devices ADD COLUMN IF NOT EXISTS cpu_loads jsonb');

        foreach (self::NEW_METRICS as $family => $metrics) {
            foreach (['5m', '1h'] as $tier) {
                $table = "{$family}_rollup_{$tier}";
                foreach ($metrics as $m => $extra) {
                    DB::statement("ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS {$m}_sum double precision");
                    foreach ($extra as $agg) {
                        DB::statement("ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS {$m}_{$agg} double precision");
                    }
                    DB::statement("ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS {$m}_cnt integer NOT NULL DEFAULT 0");
                }
            }
        }

        // Current state of each storage entry (hrStorageTable row, or the RouterOS disk/memory).
        // storage_key is the hrStorageIndex over SNMP, or a fixed name over the RouterOS API.
        DB::statement(<<<'SQL'
            CREATE TABLE device_storages (
                id bigserial PRIMARY KEY,
                device_id bigint NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
                storage_key varchar(64) NOT NULL,
                descr varchar(255) NOT NULL,
                type varchar(32) NOT NULL DEFAULT 'other',
                size_bytes bigint,
                used_bytes bigint,
                used_pct double precision,
                created_at timestamp(0) without time zone,
                updated_at timestamp(0) without time zone,
                UNIQUE (device_id, storage_key)
            )
        SQL);

        foreach (self::NEW_FAMILIES as [$raw, $keys, $cols]) {
            $defs = [];
            foreach ($keys as $k => $type) {
                $defs[] = "{$k} {$type} NOT NULL";
            }
            $defs[] = 'ts timestamp(0) without time zone NOT NULL';
            foreach ($cols as $c => $type) {
                $defs[] = "{$c} {$type}";
            }
            DB::statement("CREATE TABLE {$raw} (\n    ".implode(",\n    ", $defs)."\n) PARTITION BY RANGE (ts)");
            DB::statement("CREATE INDEX {$raw}_key_ts_idx ON {$raw} (".implode(', ', array_keys($keys)).', ts)');
            // the rollup job reads a time slice across every key, see add_ts_brin_to_samples
            DB::statement("CREATE INDEX {$raw}_ts_brin ON {$raw} USING brin (ts)");

            $day = now()->startOfDay()->subDay();
            for ($i = 0; $i < 5; $i++) {
                $d = $day->copy()->addDays($i);
                $this->partition($raw, $raw.'_'.$d->format('Ymd'), $d->format('Y-m-d 00:00:00'), $d->copy()->addDay()->format('Y-m-d 00:00:00'));
            }
        }

        foreach (self::NEW_FAMILIES as $family => [, $keyTypes, , $metrics]) {
            $keys = array_keys($keyTypes);
            foreach (['5m', '1h'] as $tier) {
                $table = "{$family}_rollup_{$tier}";
                $cols = [];
                foreach ($keys as $k) {
                    $cols[] = "{$k} bigint NOT NULL";
                }
                $cols[] = 'bucket timestamp(0) without time zone NOT NULL';
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
    }

    public function down(): void
    {
        foreach (self::NEW_FAMILIES as $family => [$raw]) {
            foreach (['5m', '1h'] as $tier) {
                DB::statement("DROP TABLE IF EXISTS {$family}_rollup_{$tier} CASCADE");
            }
            DB::statement("DROP TABLE IF EXISTS {$raw} CASCADE");
            DB::table('history_rollup_state')->where('family', $family)->delete();
        }
        DB::statement('DROP TABLE IF EXISTS device_storages');

        foreach (self::NEW_METRICS as $family => $metrics) {
            foreach (['5m', '1h'] as $tier) {
                foreach ($metrics as $m => $extra) {
                    foreach (['sum', 'cnt', ...$extra] as $agg) {
                        DB::statement("ALTER TABLE {$family}_rollup_{$tier} DROP COLUMN IF EXISTS {$m}_{$agg}");
                    }
                }
            }
        }

        DB::statement('ALTER TABLE devices DROP COLUMN IF EXISTS cpu_loads');
        DB::statement('ALTER TABLE interfaces DROP COLUMN IF EXISTS port_counters');
        foreach (self::PORT_RATES as $col) {
            DB::statement("ALTER TABLE interfaces DROP COLUMN IF EXISTS {$col}");
            DB::statement("ALTER TABLE interface_samples DROP COLUMN IF EXISTS {$col}");
        }
        DB::statement('ALTER TABLE interface_samples DROP COLUMN IF EXISTS oper_up');
        DB::statement('ALTER TABLE device_metric_samples DROP COLUMN IF EXISTS uptime_s');
    }

    private function partition(string $parent, string $name, string $from, string $to): void
    {
        DB::statement("CREATE TABLE IF NOT EXISTS \"{$name}\" PARTITION OF {$parent} FOR VALUES FROM ('{$from}') TO ('{$to}')");
    }
};
