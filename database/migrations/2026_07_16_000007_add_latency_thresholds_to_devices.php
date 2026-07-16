<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-device latency quality thresholds, used by the internet/upstream card: at or below
 * `good` reads green, at or above `bad` reads red, in between amber. Null = fall back to
 * sensible UI defaults, so existing devices need no backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->unsignedSmallInteger('latency_good_ms')->nullable()->after('loss_pct');
            $table->unsignedSmallInteger('latency_bad_ms')->nullable()->after('latency_good_ms');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['latency_good_ms', 'latency_bad_ms']);
        });
    }
};
