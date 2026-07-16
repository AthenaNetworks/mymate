<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subnets', function (Blueprint $table) {
            // Set when a sweep starts, cleared when it finishes - so the UI can show live
            // progress for ANY scan (user-triggered or scheduled), not just ones this browser
            // kicked off. A stale value (worker killed mid-sweep) is treated as not-scanning
            // by age in the resource.
            $table->timestamp('scanning_since')->nullable()->after('last_scanned_at');
        });
    }

    public function down(): void
    {
        Schema::table('subnets', function (Blueprint $table) {
            $table->dropColumn('scanning_since');
        });
    }
};
