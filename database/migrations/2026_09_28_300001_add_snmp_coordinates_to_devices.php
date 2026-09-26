<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GitHub #22: the coordinates the device's own SNMP / RouterOS location last advertised, kept
 * separately from latitude/longitude. Those two get locked once an operator pins the device by
 * hand, so without this the SNMP coords were thrown away and "use the SNMP location" had nothing
 * to hand the device back to until the next discovery pass. Null = the location carried no
 * coordinates (or we haven't captured it yet).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->decimal('snmp_latitude', 10, 7)->nullable()->after('geo_source');
            $table->decimal('snmp_longitude', 10, 7)->nullable()->after('snmp_latitude');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['snmp_latitude', 'snmp_longitude']);
        });
    }
};
