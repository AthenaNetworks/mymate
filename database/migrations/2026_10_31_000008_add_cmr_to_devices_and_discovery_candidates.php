<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MikroTik CMR controller (the `cmr-ctrl` sidecar). A CMR device dials in, pairs, and is keyed by
 * the daemon's device id (its first MAC). It lands in the discovery review queue like a scanned
 * host and becomes a device when an admin approves it.
 *
 * Several CMR devices can sit behind one NAT address, so a CMR candidate is unique by its device id,
 * not by IP: the per-scope IP unique index only covers scanned candidates from now on.
 * All additive; scanned candidates and existing devices are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->string('cmr_device_id')->nullable()->unique(); // daemon device id (first MAC)
            $table->string('cmr_mac', 32)->nullable();
            $table->string('cmr_board')->nullable();
            $table->string('cmr_os')->nullable();
            $table->boolean('cmr_connected')->default(false);
            $table->string('cmr_remote_addr', 45)->nullable(); // source IP of its last connection (may be NAT)
            $table->timestamp('cmr_first_seen')->nullable();
            $table->timestamp('cmr_last_seen')->nullable();
        });

        Schema::table('discovery_candidates', function (Blueprint $table): void {
            $table->string('source')->default('scan'); // scan | cmr
            $table->string('cmr_device_id')->nullable()->unique();
            $table->string('mac', 32)->nullable();
            $table->string('board')->nullable();
            $table->string('os')->nullable();
        });

        DB::statement('DROP INDEX IF EXISTS discovery_candidates_scope_ip_unique');
        DB::statement('CREATE UNIQUE INDEX discovery_candidates_scope_ip_unique ON discovery_candidates ((COALESCE(agent_id, 0)), ip) WHERE cmr_device_id IS NULL');
    }

    public function down(): void
    {
        DB::table('discovery_candidates')->whereNotNull('cmr_device_id')->delete();
        DB::statement('DROP INDEX IF EXISTS discovery_candidates_scope_ip_unique');
        DB::statement('CREATE UNIQUE INDEX discovery_candidates_scope_ip_unique ON discovery_candidates ((COALESCE(agent_id, 0)), ip)');

        Schema::table('discovery_candidates', function (Blueprint $table): void {
            $table->dropColumn(['source', 'cmr_device_id', 'mac', 'board', 'os']);
        });
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropColumn(['cmr_device_id', 'cmr_mac', 'cmr_board', 'cmr_os', 'cmr_connected', 'cmr_remote_addr', 'cmr_first_seen', 'cmr_last_seen']);
        });
    }
};
