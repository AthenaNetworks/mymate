<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consecutive missed up/down sweeps for a device. The ping loop only flips a device to `down`
 * after it has missed `mymate.ping.fail_threshold` sweeps in a row - a single dropped reply (a
 * blip of packet loss, an over-busy sweep) no longer alarms. Recovery is still immediate: one
 * reply resets the streak and marks it up. Capped at the threshold so a long-down device isn't
 * written every sweep.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->unsignedSmallInteger('fail_streak')->default(0)->after('last_change');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('fail_streak');
        });
    }
};
