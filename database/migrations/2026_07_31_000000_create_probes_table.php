<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Service probes (GitHub #19): operator-defined checks that reach a device over something other
 * than SNMP/ping - an HTTP(S) request or a raw TCP connect - so you can monitor a web UI, an API
 * endpoint or a service port on gear that speaks neither SNMP nor the RouterOS API. Each probe
 * carries its own up/down + latency (and, for HTTPS, the certificate expiry), with the trend in
 * the partitioned `probe_samples`. This is My Mate's take on The Dude's custom probes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('probes', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('kind');                          // 'http' | 'tcp'
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('interval_s')->default(60);
            $table->unsignedInteger('timeout_ms')->default(5000);
            // How many consecutive failures before it flips to down (flap dampening, like ping).
            $table->unsignedSmallInteger('fail_threshold')->default(2);
            $table->jsonb('config')->nullable();             // kind-specific: url/method/expect... or host/port

            // Live result (latest check).
            $table->string('status')->default('unknown');    // up | down | unknown
            $table->double('latency_ms')->nullable();
            $table->string('message')->nullable();           // short why (e.g. "HTTP 503", "connection refused")
            $table->timestamp('cert_expires_at')->nullable(); // HTTPS only - TLS certificate expiry
            $table->unsignedSmallInteger('fail_streak')->default(0);
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->index(['device_id', 'enabled']);
        });

        // Partitioned status/latency trend, pruned by history.retention_days like the other samples.
        DB::statement(<<<'SQL'
            CREATE TABLE probe_samples (
                probe_id bigint NOT NULL,
                ts timestamp(0) without time zone NOT NULL,
                up boolean,
                latency_ms double precision
            ) PARTITION BY RANGE (ts)
        SQL);
        DB::statement('CREATE INDEX probe_samples_key_ts_idx ON probe_samples (probe_id, ts)');

        $day = now()->startOfDay()->subDay();
        for ($i = 0; $i < 5; $i++) {
            $date = $day->copy()->addDays($i);
            $name = 'probe_samples_'.$date->format('Ymd');
            $from = $date->format('Y-m-d 00:00:00');
            $to = $date->copy()->addDay()->format('Y-m-d 00:00:00');
            DB::statement("CREATE TABLE IF NOT EXISTS \"{$name}\" PARTITION OF probe_samples FOR VALUES FROM ('{$from}') TO ('{$to}')");
        }
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS probe_samples CASCADE');
        Schema::dropIfExists('probes');
    }
};
