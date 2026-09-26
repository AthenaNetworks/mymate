<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fibre/SFP optical power per interface (GitHub #11). The latest Rx/Tx reading in dBm, read on the
 * metrics cadence (RouterOS API ethernet monitor, or a vendor SNMP optical table), plus when it was
 * read so the optical-power alert can ignore a frozen value. Null = no module / not readable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interfaces', function (Blueprint $table): void {
            $table->double('optical_rx_dbm')->nullable();
            $table->double('optical_tx_dbm')->nullable();
            $table->timestamp('optical_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('interfaces', function (Blueprint $table): void {
            $table->dropColumn(['optical_rx_dbm', 'optical_tx_dbm', 'optical_at']);
        });
    }
};
