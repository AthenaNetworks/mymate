<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-map geographic mode (GitHub #11): when on, the map renders on a Leaflet basemap scoped to
 * its devices' real coordinates instead of the free-form React Flow canvas. Off by default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maps', function (Blueprint $table) {
            $table->boolean('leaflet_enabled')->default(false)->after('node_y');
        });
    }

    public function down(): void
    {
        Schema::table('maps', function (Blueprint $table) {
            $table->dropColumn('leaflet_enabled');
        });
    }
};
