<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * : device down->up history. One row per outage - opened when a device
 * goes down, closed (ended_at + duration_s) when it recovers. Backs the Outages
 * timeline + the inspector's recent-outages, and feeds down/recovered alerting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();   // null = still down (open outage)
            $table->integer('duration_s')->nullable();   // filled on recovery
            $table->string('cause')->nullable();
            $table->timestamps();

            $table->index(['device_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outages');
    }
};
