<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Firmware upgrade tracking: the device's current RouterOS version, the
 * latest available for its channel, and the live upgrade lifecycle so the UI can
 * show a spinner -> "done, on latest". All additive + nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('os_version')->nullable();      // current RouterOS version
            $table->string('latest_version')->nullable();  // latest for the channel, at last check
            $table->string('upgrade_status')->nullable();  // queued|checking|downloading|rebooting|done|up_to_date|failed
            $table->string('upgrade_message')->nullable();
            $table->timestamp('upgrade_at')->nullable();    // when upgrade_status last changed
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['os_version', 'latest_version', 'upgrade_status', 'upgrade_message', 'upgrade_at']);
        });
    }
};
