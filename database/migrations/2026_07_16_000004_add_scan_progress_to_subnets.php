<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subnets', function (Blueprint $table) {
            // Live sweep counters streamed by a remote agent (scan_start / scan_progress), so the
            // UI can show a real progress bar. Null between scans / for central sweeps (which
            // report no per-host progress).
            $table->unsignedInteger('scan_total')->nullable()->after('scanning_since');
            $table->unsignedInteger('scan_swept')->nullable()->after('scan_total');
            $table->unsignedInteger('scan_found')->nullable()->after('scan_swept');
        });
    }

    public function down(): void
    {
        Schema::table('subnets', function (Blueprint $table) {
            $table->dropColumn(['scan_total', 'scan_swept', 'scan_found']);
        });
    }
};
