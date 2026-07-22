<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An optional RouterOS-API credential for a device (GitHub #11). Lets an SNMP-polled MikroTik
 * still expose data that only the RouterOS API has - currently the OSPF full-neighbour count,
 * which RouterOS doesn't publish over SNMP. Mirrors ssh_credential_id (a second credential for
 * a non-poll purpose). Null = no API reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->foreignId('routeros_credential_id')->nullable()->after('ssh_credential_id')
                ->constrained('credentials')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('routeros_credential_id');
        });
    }
};
