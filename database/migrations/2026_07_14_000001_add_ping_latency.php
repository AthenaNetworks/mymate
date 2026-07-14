<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ping latency / packet-loss / jitter. Live latest values live on the device row (mirroring
 * the cpu/mem/temp pattern); the trend window is a RANGE-partitioned append table like
 * device_metric_samples, rolled + retention-dropped by App\Actions\History\ManageHistoryPartitions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->double('rtt_ms')->nullable();    // last mean round-trip time (ms)
            $table->double('loss_pct')->nullable();  // last packet loss (0-100)
            $table->timestamp('ping_at')->nullable(); // last latency sample time
        });

        DB::statement(<<<'SQL'
            CREATE TABLE ping_samples (
                device_id bigint NOT NULL,
                ts timestamp(0) without time zone NOT NULL,
                rtt_ms double precision,
                loss_pct double precision,
                jitter_ms double precision
            ) PARTITION BY RANGE (ts)
        SQL);

        DB::statement('CREATE INDEX ping_samples_device_ts_idx ON ping_samples (device_id, ts)');

        // Seed [yesterday .. +3 days] so writes work right after migrate.
        $day = now()->startOfDay()->subDay();
        for ($i = 0; $i < 5; $i++) {
            $date = $day->copy()->addDays($i);
            $name = 'ping_samples_'.$date->format('Ymd');
            $from = $date->format('Y-m-d 00:00:00');
            $to = $date->copy()->addDay()->format('Y-m-d 00:00:00');
            DB::statement("CREATE TABLE IF NOT EXISTS \"{$name}\" PARTITION OF ping_samples FOR VALUES FROM ('{$from}') TO ('{$to}')");
        }
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS ping_samples CASCADE');

        Schema::table('devices', function (Blueprint $table): void {
            $table->dropColumn(['rtt_ms', 'loss_pct', 'ping_at']);
        });
    }
};
