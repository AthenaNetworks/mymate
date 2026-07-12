<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * : multiple maps. `maps` is nestable (town -> region) via parent_map_id;
 * `device_map_positions` places a device on a map with per-map coordinates, so one
 * device can appear on several maps. Links are unchanged - "inter-map" is a render
 * concern (a link whose two ends sit on different maps).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maps', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->foreignId('parent_map_id')->nullable()->constrained('maps')->nullOnDelete();
            $table->boolean('is_default')->default(false);
            $table->integer('position')->default(0);
            $table->timestamps();
        });

        Schema::create('device_map_positions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('map_id')->constrained('maps')->cascadeOnDelete();
            $table->double('x')->default(0);
            $table->double('y')->default(0);
            $table->timestamps();

            $table->unique(['device_id', 'map_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_map_positions');
        Schema::dropIfExists('maps');
    }
};
