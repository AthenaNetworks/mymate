<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suggested links from neighbour discovery (GitHub #85): LLDP and CDP over SNMP, and the RouterOS
 * neighbour table (MNDP/LLDP/CDP) over the API. Nothing is drawn until an operator accepts one.
 *
 * Each row is one cable, stored with the lower device id on the `a` side so both ends reporting
 * the same cable land on the same `pair_key` ("a_dev:a_if:b_dev:b_if", "-" for an unknown port).
 * An ignored suggestion keeps its row, so it stays ignored when it's seen again.
 *
 * `devices.lldp_chassis_id` is the device's own LLDP chassis id, so another device's neighbour
 * entry can be matched to it even when the names and addresses differ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('link_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('a_device_id')->constrained('devices')->cascadeOnDelete();
            $table->foreignId('a_interface_id')->nullable()->constrained('interfaces')->cascadeOnDelete();
            $table->foreignId('b_device_id')->constrained('devices')->cascadeOnDelete();
            $table->foreignId('b_interface_id')->nullable()->constrained('interfaces')->cascadeOnDelete();
            $table->string('pair_key', 80)->unique();
            $table->string('protocol', 8); // lldp | cdp | mndp
            $table->string('status', 12)->default('new'); // new | ignored
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at')->index();
            $table->timestamps();
        });

        Schema::table('devices', function (Blueprint $table) {
            $table->string('lldp_chassis_id', 64)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('link_suggestions');
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('lldp_chassis_id');
        });
    }
};
