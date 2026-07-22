<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Geographic position for a device (GitHub #11) - for the geo map overlay. Set by drag/drop, an
 * address lookup, or auto-derived from an SNMP sysLocation that carries "[lat, lng]". Null = not
 * placed. `geo_source` records how it was set so an auto-derive never clobbers a manual pin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('map_y');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->string('geo_source', 12)->nullable()->after('longitude'); // manual | address | snmp
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude', 'geo_source']);
        });
    }
};
