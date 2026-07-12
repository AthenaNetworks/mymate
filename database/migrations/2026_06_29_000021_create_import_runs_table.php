<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Dude import (FR-Dude): one row per uploaded dude.db import. Tracks the
 * uploaded file, the chosen mode (fresh vs upsert) + options, live status, and a
 * JSON summary of what was created/updated. Drives the import progress UI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_runs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('original_filename');
            $table->string('stored_path')->nullable();          // storage path of the uploaded .db
            $table->string('mode')->default('upsert');           // fresh | upsert
            $table->boolean('include_history')->default(true);   // import chart_values -> interface_samples
            $table->string('status')->default('pending');        // pending|extracting|importing|completed|failed|cancelled
            $table->string('stage')->nullable();                 // human stage label (e.g. "Importing devices")
            $table->jsonb('progress')->nullable();               // {percent, detail, eta_seconds, processed, total}
            $table->boolean('cancel_requested')->default(false); // operator asked to stop - job checks at safe points
            $table->jsonb('summary')->nullable();                // {devices:{created,updated}, links:..., samples:...}
            $table->text('error')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();   // who ran it (soft ref - no FK, users may be pruned)
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_runs');
    }
};
