<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store the latest raw throughput (bits/sec) alongside the derived util%. Util needs
 * a known speed (util = bps / capacity); when an interface has no speed (tunnels,
 * Dude links with speed 0) we still want to SHOW throughput and colour/scale by it -
 * so the rate itself becomes first-class, not just the percentage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interfaces', function (Blueprint $table): void {
            $table->bigInteger('bps_in')->nullable()->after('util_out');
            $table->bigInteger('bps_out')->nullable()->after('bps_in');
        });
    }

    public function down(): void
    {
        Schema::table('interfaces', function (Blueprint $table): void {
            $table->dropColumn(['bps_in', 'bps_out']);
        });
    }
};
