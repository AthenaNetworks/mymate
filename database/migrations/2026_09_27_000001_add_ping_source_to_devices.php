<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-device ping source address (GitHub #11). When set, the up/down sweep pings this device
 * FROM that local address (fping -S centrally, a bound ICMP socket on an agent), eg to check a
 * customer path can reach the internet. Null = the global MYMATE_PING_SOURCE, else the OS route.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->string('ping_source', 45)->nullable()->after('mgmt_ip');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropColumn('ping_source');
        });
    }
};
