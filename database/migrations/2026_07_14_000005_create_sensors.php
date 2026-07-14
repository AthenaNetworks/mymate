<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Custom SNMP sensors: operator-defined OIDs polled on the in-scope devices, so you can
 * graph anything the gear exposes (interface errors, PoE draw, UPS charge, disk, a probe)
 * beyond the built-in cpu/mem/temp/RF. `sensors` are the definitions, `sensor_readings`
 * the current value per (sensor, device), and sensor_samples the partitioned trend.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sensors', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('oid');                       // scalar OID to GET
            $table->string('unit')->nullable();          // display suffix, e.g. "%", "V", "°C"
            $table->double('divisor')->default(1);       // raw value / divisor (scaling)
            $table->jsonb('scope')->nullable();          // targeting bag; null = all devices
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('sensor_readings', function (Blueprint $table): void {
            $table->unsignedBigInteger('sensor_id');
            $table->unsignedBigInteger('device_id');
            $table->double('value')->nullable();
            $table->timestamp('read_at');
            $table->primary(['sensor_id', 'device_id']);
            $table->foreign('sensor_id')->references('id')->on('sensors')->cascadeOnDelete();
            $table->foreign('device_id')->references('id')->on('devices')->cascadeOnDelete();
        });

        DB::statement(<<<'SQL'
            CREATE TABLE sensor_samples (
                sensor_id bigint NOT NULL,
                device_id bigint NOT NULL,
                ts timestamp(0) without time zone NOT NULL,
                value double precision
            ) PARTITION BY RANGE (ts)
        SQL);
        DB::statement('CREATE INDEX sensor_samples_key_ts_idx ON sensor_samples (sensor_id, device_id, ts)');

        $day = now()->startOfDay()->subDay();
        for ($i = 0; $i < 5; $i++) {
            $date = $day->copy()->addDays($i);
            $name = 'sensor_samples_'.$date->format('Ymd');
            $from = $date->format('Y-m-d 00:00:00');
            $to = $date->copy()->addDay()->format('Y-m-d 00:00:00');
            DB::statement("CREATE TABLE IF NOT EXISTS \"{$name}\" PARTITION OF sensor_samples FOR VALUES FROM ('{$from}') TO ('{$to}')");
        }
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS sensor_samples CASCADE');
        Schema::dropIfExists('sensor_readings');
        Schema::dropIfExists('sensors');
    }
};
