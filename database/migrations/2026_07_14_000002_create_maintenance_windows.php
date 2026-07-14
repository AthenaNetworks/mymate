<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Maintenance windows: a scheduled span during which alerts are suppressed for the
 * in-scope devices, so planned work (a reboot, an upgrade batch, tower maintenance)
 * doesn't page anyone. `scope` is the same targeting bag alert policies use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_windows', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('name');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->jsonb('scope')->nullable();   // null = all devices
            $table->boolean('enabled')->default(true);
            $table->unsignedBigInteger('created_by')->nullable(); // soft ref to users
            $table->timestamps();

            $table->index(['enabled', 'starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_windows');
    }
};
