<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saved canvas position of an inter-map link's "portal" node on a given map, so an
 * operator can drag those portals around and have it stick (like device positions in
 * device_map_positions). One position per (map, link).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('map_link_positions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('map_id')->constrained('maps')->cascadeOnDelete();
            $table->foreignId('link_id')->constrained('links')->cascadeOnDelete();
            $table->double('x');
            $table->double('y');
            $table->timestamps();

            $table->unique(['map_id', 'link_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('map_link_positions');
    }
};
