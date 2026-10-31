<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Labels on the geo map (GitHub #52): a note can sit at a real-world point as well as at its x/y on
 * the logical canvas. Null = not on the geo map.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('map_notes', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('map_notes', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude']);
        });
    }
};
