<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A dedicated SSH credential per device for config backups, separate from the SNMP/RouterOS
 * credential used for polling. Nullable - backups fall back to the device's poll credential
 * (if SSH-usable) then the Settings default, as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->foreignId('ssh_credential_id')->nullable()->after('credential_id')
                ->constrained('credentials')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ssh_credential_id');
        });
    }
};
