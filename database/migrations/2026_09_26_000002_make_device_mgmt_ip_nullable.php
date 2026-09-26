<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Static map objects (GitHub #9 / #28 / #49): a device with no management IP. A dumb switch, a patch
 * panel or an upstream you can't reach still wants to be drawn and linked to, like The Dude's static
 * elements - it just must never be polled. Rather than a separate table (links reference devices), the
 * device's IP simply becomes optional; Device::scopePollable() keeps IP-less devices out of every job.
 *
 * The per-scope unique index (COALESCE(agent_id, 0), mgmt_ip) treats NULLs as distinct, so any number
 * of static objects can coexist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->string('mgmt_ip', 45)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Static objects have no IP to fall back to; give them an unroutable placeholder rather than
        // failing the rollback (TEST-NET-1, RFC 5737 - never a real host).
        foreach (\Illuminate\Support\Facades\DB::table('devices')->whereNull('mgmt_ip')->pluck('id') as $i => $id) {
            \Illuminate\Support\Facades\DB::table('devices')->where('id', $id)
                ->update(['mgmt_ip' => '192.0.2.'.(($i % 254) + 1)]);
        }
        Schema::table('devices', function (Blueprint $table): void {
            $table->string('mgmt_ip', 45)->nullable(false)->change();
        });
    }
};
