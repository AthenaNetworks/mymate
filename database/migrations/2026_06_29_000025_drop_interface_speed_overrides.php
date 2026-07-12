<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * : interface speed is now read-only from SNMP, and the bandwidth
     * override (incl. asymmetry) lives on the link. Drop the two per-interface override
     * columns. Column-drops don't delete rows - `speed_mbps` (the SNMP value) stays.
     */
    public function up(): void
    {
        Schema::table('interfaces', function (Blueprint $table): void {
            $table->dropColumn(['speed_up_mbps', 'speed_overridden']);
        });
    }

    public function down(): void
    {
        Schema::table('interfaces', function (Blueprint $table): void {
            $table->unsignedInteger('speed_up_mbps')->nullable();
            $table->boolean('speed_overridden')->default(false);
        });
    }
};
