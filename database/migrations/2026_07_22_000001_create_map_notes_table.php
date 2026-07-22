<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Free-text notes / labels placed on a map (GitHub #11) - annotations that aren't tied to a
 * device or link. Position + an optional accent colour. Cascade with the map.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('map_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('map_id')->constrained('maps')->cascadeOnDelete();
            $table->string('text', 500);
            $table->double('x')->default(0);
            $table->double('y')->default(0);
            $table->string('color', 20)->nullable();
            $table->timestamps();

            $table->index('map_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('map_notes');
    }
};
