<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NOC-console metadata: a coarse device type for the node badge, a
 * self-referential parent for the hierarchy/inspector, and vendor/model/uptime
 * facts captured best-effort on the discovery cadence. All additive + nullable;
 * the live up/down + throughput paths are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('device_type')->default('unknown'); // router|switch|ap|server|internet|unknown
            $table->foreignId('parent_device_id')->nullable()
                ->constrained('devices')->nullOnDelete();
            $table->string('vendor')->nullable();
            $table->string('model')->nullable();
            $table->unsignedBigInteger('uptime_seconds')->nullable();
            $table->timestamp('uptime_at')->nullable(); // when uptime_seconds was captured

            $table->index('parent_device_id');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_device_id');
            $table->dropColumn(['device_type', 'vendor', 'model', 'uptime_seconds', 'uptime_at']);
        });
    }
};
