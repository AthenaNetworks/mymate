<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * A management IP is unique per polling scope, not across the whole install (GitHub #49).
 *
 * With remote agents, sites routinely reuse the same private subnets - 192.168.1.10 behind Site A's
 * agent and 192.168.1.10 behind Site B's are different boxes. The Dude handles that because each
 * site's router does its own polling; the old global unique on devices.mgmt_ip made it impossible
 * here. The scope is the agent that polls the device, or the central server (agent_id null), which
 * is what an expression index over COALESCE(agent_id, 0) enforces.
 *
 * Discovery candidates get the same treatment, plus an agent_id they never had: a host found by an
 * agent's subnet sweep used to be promoted as a *central* device the server couldn't reach. Existing
 * candidates are backfilled from the agent subnets that contain their IP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropUnique(['mgmt_ip']);
        });
        DB::statement('CREATE UNIQUE INDEX devices_scope_mgmt_ip_unique ON devices ((COALESCE(agent_id, 0)), mgmt_ip)');

        Schema::table('discovery_candidates', function (Blueprint $table): void {
            // Cascade: a candidate belongs to the agent's network; once the agent is gone it means nothing.
            $table->foreignId('agent_id')->nullable()->after('ip')->constrained('agents')->cascadeOnDelete();
            $table->dropUnique(['ip']);
        });
        DB::statement('CREATE UNIQUE INDEX discovery_candidates_scope_ip_unique ON discovery_candidates ((COALESCE(agent_id, 0)), ip)');

        // Backfill: a candidate whose IP sits in exactly one agent's subnet was found by that agent.
        $subnets = DB::table('subnets')->whereNotNull('agent_id')->get(['cidr', 'agent_id']);
        if ($subnets->isEmpty()) {
            return;
        }
        foreach (DB::table('discovery_candidates')->whereNull('agent_id')->get(['id', 'ip']) as $c) {
            $agents = $subnets->filter(fn ($s) => IpUtils::checkIp($c->ip, $s->cidr))->pluck('agent_id')->unique();
            if ($agents->count() === 1) {
                DB::table('discovery_candidates')->where('id', $c->id)->update(['agent_id' => $agents->first()]);
            }
        }
    }

    public function down(): void
    {
        // Re-adding the global uniques fails if duplicate IPs now exist across agents - resolve those first.
        DB::statement('DROP INDEX IF EXISTS discovery_candidates_scope_ip_unique');
        Schema::table('discovery_candidates', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('agent_id');
            $table->unique('ip');
        });

        DB::statement('DROP INDEX IF EXISTS devices_scope_mgmt_ip_unique');
        Schema::table('devices', function (Blueprint $table): void {
            $table->unique('mgmt_ip');
        });
    }
};
