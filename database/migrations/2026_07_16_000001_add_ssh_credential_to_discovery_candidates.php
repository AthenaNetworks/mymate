<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discovery_candidates', function (Blueprint $table) {
            // A matching SSH credential (for config backups), found alongside the poll credential
            // (SNMP/RouterOS) when probing a responder. Linked onto the device on promotion.
            $table->foreignId('matched_ssh_credential_id')->nullable()->after('matched_credential_id')
                ->constrained('credentials')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('discovery_candidates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('matched_ssh_credential_id');
        });
    }
};
