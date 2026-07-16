<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manual links drawn on an overview map between two child-map nodes - a top-level topology that
 * isn't tied to any device or interface (GitHub #9). Separate from `links` (which are the live,
 * device/interface-bound circuits) so the device-polling pipeline stays untouched; these are
 * static, operator-drawn, and carry only a media type + optional label.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('map_links', function (Blueprint $table) {
            $table->id();
            // The canvas this link is drawn on, and the two child-map nodes it connects.
            $table->foreignId('map_id')->constrained('maps')->cascadeOnDelete();
            $table->foreignId('a_map_id')->constrained('maps')->cascadeOnDelete();
            $table->foreignId('b_map_id')->constrained('maps')->cascadeOnDelete();
            $table->string('a_handle', 16)->nullable();
            $table->string('b_handle', 16)->nullable();
            $table->string('media_type', 20)->nullable();
            $table->string('label', 80)->nullable();
            $table->timestamps();

            $table->index('map_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('map_links');
    }
};
