<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subnets', function (Blueprint $table) {
            $table->id();
            $table->string('cidr', 45); // IPv4 network/prefix, e.g. 10.80.111.0/24
            $table->string('label')->nullable();
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('scan_interval_s')->default(3600); // per-subnet cadence
            $table->timestamp('last_scanned_at')->nullable();
            $table->timestamps();

            // Drives the loop's "which subnets are due to scan?" query.
            $table->index(['enabled', 'last_scanned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subnets');
    }
};
