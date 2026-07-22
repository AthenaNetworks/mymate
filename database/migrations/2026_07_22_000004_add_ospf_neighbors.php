<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Count of OSPF neighbours in the full state (GitHub #11). Rides the same device-metrics
 * pipeline as cpu/mem/temp: live value on the device row, trend in device_metric_samples.
 * Only populated for MikroTik devices polled over the RouterOS API - the standard OSPF-MIB
 * isn't exposed over SNMP, so an SNMP-polled device leaves this null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->unsignedSmallInteger('ospf_neighbors')->nullable();
        });

        DB::statement('ALTER TABLE device_metric_samples ADD COLUMN ospf_neighbors integer');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE device_metric_samples DROP COLUMN IF EXISTS ospf_neighbors');

        Schema::table('devices', function (Blueprint $table): void {
            $table->dropColumn('ospf_neighbors');
        });
    }
};
