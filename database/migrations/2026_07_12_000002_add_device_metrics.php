<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Device resource metrics - CPU / memory / temperature. Live latest values live on the
 * device row (the fast path the map tile reads, mirroring interfaces.util_*); the trend
 * window is a RANGE-partitioned append table like interface_samples, dropped by day for
 * retention (App\Actions\History\ManageHistoryPartitions rolls both tables).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->double('cpu_pct')->nullable();       // 0-100
            $table->double('mem_used_pct')->nullable();  // 0-100
            $table->double('temp_c')->nullable();        // celsius
            $table->timestamp('metrics_at')->nullable(); // last successful metrics poll
        });

        DB::statement(<<<'SQL'
            CREATE TABLE device_metric_samples (
                device_id bigint NOT NULL,
                ts timestamp(0) without time zone NOT NULL,
                cpu_pct double precision,
                mem_used_pct double precision,
                temp_c double precision
            ) PARTITION BY RANGE (ts)
        SQL);

        DB::statement('CREATE INDEX device_metric_samples_device_ts_idx ON device_metric_samples (device_id, ts)');

        // Seed [yesterday .. +3 days] so writes work right after migrate.
        $day = now()->startOfDay()->subDay();
        for ($i = 0; $i < 5; $i++) {
            $date = $day->copy()->addDays($i);
            $name = 'device_metric_samples_'.$date->format('Ymd');
            $from = $date->format('Y-m-d 00:00:00');
            $to = $date->copy()->addDay()->format('Y-m-d 00:00:00');
            DB::statement("CREATE TABLE IF NOT EXISTS \"{$name}\" PARTITION OF device_metric_samples FOR VALUES FROM ('{$from}') TO ('{$to}')");
        }
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS device_metric_samples CASCADE');

        Schema::table('devices', function (Blueprint $table): void {
            $table->dropColumn(['cpu_pct', 'mem_used_pct', 'temp_c', 'metrics_at']);
        });
    }
};
