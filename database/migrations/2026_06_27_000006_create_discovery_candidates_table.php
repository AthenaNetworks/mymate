<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discovery_candidates', function (Blueprint $table) {
            $table->id();
            // One candidate per IP - a re-scan updates last_seen, it never duplicates.
            $table->string('ip', 45)->unique();
            $table->string('status')->default('new'); // new | approved | ignored (enum cast)
            $table->string('sysname')->nullable(); // SNMP sysName / RouterOS identity, if identified
            $table->string('detected_method')->nullable(); // snmp | routeros (enum cast); null = responded, unidentified
            // The credential from the read-only pool that matched (carried into the device on approve).
            $table->foreignId('matched_credential_id')->nullable()
                ->constrained('credentials')->nullOnDelete();
            $table->timestamp('first_seen')->nullable();
            $table->timestamp('last_seen')->nullable();
            $table->timestamps();

            $table->index('status'); // review-queue filter
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discovery_candidates');
    }
};
