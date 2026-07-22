<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-interface operational status ('up' / 'down') from ifOperStatus (GitHub #11) so a policy
 * can alert on a single port going down even while the device (and its uplink) stay up. Null =
 * not polled (e.g. RouterOS / agent paths that don't report it yet) - treated as unknown, never
 * fires an alert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interfaces', function (Blueprint $table) {
            $table->string('oper_status', 8)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('interfaces', function (Blueprint $table) {
            $table->dropColumn('oper_status');
        });
    }
};
