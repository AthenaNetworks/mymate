<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-map ping cadence override (GitHub #32). A map can set its own up/down ping interval so a
 * critical-links map runs at 5s while a bulk-CPE map runs at 60s to save resources. Null = use
 * the global `ping.interval`. A device on several override maps takes the fastest of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maps', function (Blueprint $table) {
            $table->unsignedSmallInteger('ping_interval')->nullable()->after('leaflet_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('maps', function (Blueprint $table) {
            $table->dropColumn('ping_interval');
        });
    }
};
