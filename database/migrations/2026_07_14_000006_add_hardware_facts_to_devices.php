<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Static hardware inventory captured alongside vendor/model/os on the discovery cadence
 * (CaptureDeviceFacts): serial number, CPU description and total RAM. Distinct from the live
 * cpu/mem/temp metrics - these are the fixed facts of the box, shown in the inspector.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->string('serial')->nullable();
            $table->string('cpu')->nullable();          // e.g. "ARM 4-core @ 880MHz", "Intel Xeon"
            $table->unsignedBigInteger('ram_bytes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropColumn(['serial', 'cpu', 'ram_bytes']);
        });
    }
};
