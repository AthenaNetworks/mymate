<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Undo stack for map layouts. Before an auto-tidy re-places a map's devices, we snapshot the
 * map's current device positions here so the operator can roll back - from any browser, and for
 * as long as the snapshot survives on the stack (trimmed to the newest few per map).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('map_layout_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('map_id')->constrained()->cascadeOnDelete();
            // { "<device_id>": { "x": <float>, "y": <float> }, ... } - the map's positions before a tidy.
            $table->jsonb('positions');
            $table->string('note')->nullable(); // e.g. "Dependency tidy from FIN-SW-C-01-01"
            $table->timestamp('created_at')->useCurrent();

            $table->index(['map_id', 'created_at']); // newest-first pops
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('map_layout_snapshots');
    }
};
