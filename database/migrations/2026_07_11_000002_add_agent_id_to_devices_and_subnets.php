<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Assign a device (and a discovery subnet) to a remote agent. NULL = polled/scanned
 * centrally by this app, exactly as before; set = the work is delegated to that agent,
 * which reaches the target on its local network. On agent delete the link is nulled
 * (the device/subnet reverts to central handling) rather than cascade-deleting the device.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->foreignId('agent_id')->nullable()->after('credential_id')
                ->constrained('agents')->nullOnDelete();
        });
        Schema::table('subnets', function (Blueprint $table) {
            $table->foreignId('agent_id')->nullable()
                ->constrained('agents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agent_id');
        });
        Schema::table('subnets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agent_id');
        });
    }
};
