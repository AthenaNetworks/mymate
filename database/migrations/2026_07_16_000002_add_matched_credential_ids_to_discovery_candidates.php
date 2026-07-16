<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discovery_candidates', function (Blueprint $table) {
            // Every credential id that authenticated against this host (SNMP + RouterOS + SSH),
            // for display as tags in the review queue. The single matched_credential_id /
            // matched_ssh_credential_id columns still drive promotion; this is the full set.
            $table->json('matched_credential_ids')->nullable()->after('matched_ssh_credential_id');
        });
    }

    public function down(): void
    {
        Schema::table('discovery_candidates', function (Blueprint $table) {
            $table->dropColumn('matched_credential_ids');
        });
    }
};
