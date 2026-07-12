<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `monitored` gates a device into the ping + throughput loops. Defaults true, so the
 * whole existing fleet keeps polling. When false the loops skip the device entirely -
 * used to pause monitoring, and by the `mymate:mock` demo so its fake status/util is
 * never overwritten by a real sweep.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->boolean('monitored')->default(true)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('monitored');
        });
    }
};
