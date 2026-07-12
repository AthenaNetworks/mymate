<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manual bandwidth override: when an operator sets an interface's
 * real link speed (e.g. a 1 G port whose radio link is only 300 Mbps), this flag
 * marks the capacity as operator-owned so re-discovery never clobbers it. Additive +
 * defaults false; the live up/down + throughput paths are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interfaces', function (Blueprint $table) {
            $table->boolean('speed_overridden')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('interfaces', function (Blueprint $table) {
            $table->dropColumn('speed_overridden');
        });
    }
};
