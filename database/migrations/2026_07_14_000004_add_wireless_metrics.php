<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wireless / RF metrics - signal strength, signal-to-noise, connection quality and
 * registered client count. These ride the same device-metrics pipeline as cpu/mem/temp:
 * live values on the device row, trend in device_metric_samples. Only populated for
 * wireless gear (MikroTik radios over the RouterOS API; SNMP where a profile provides OIDs).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->double('signal_dbm')->nullable();       // dBm (negative)
            $table->double('snr_db')->nullable();            // dB
            $table->double('ccq_pct')->nullable();           // 0-100
            $table->unsignedInteger('wireless_clients')->nullable();
        });

        DB::statement('ALTER TABLE device_metric_samples ADD COLUMN signal_dbm double precision');
        DB::statement('ALTER TABLE device_metric_samples ADD COLUMN snr_db double precision');
        DB::statement('ALTER TABLE device_metric_samples ADD COLUMN ccq_pct double precision');
        DB::statement('ALTER TABLE device_metric_samples ADD COLUMN wireless_clients integer');
    }

    public function down(): void
    {
        foreach (['signal_dbm', 'snr_db', 'ccq_pct', 'wireless_clients'] as $col) {
            DB::statement("ALTER TABLE device_metric_samples DROP COLUMN IF EXISTS {$col}");
        }

        Schema::table('devices', function (Blueprint $table): void {
            $table->dropColumn(['signal_dbm', 'snr_db', 'ccq_pct', 'wireless_clients']);
        });
    }
};
