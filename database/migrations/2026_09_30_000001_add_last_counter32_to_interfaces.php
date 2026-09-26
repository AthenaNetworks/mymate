<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which counter width `last_in` / `last_out` came from. A v2c box whose 64-bit octet walk goes
 * missing for a tick falls back to the 32-bit ifTable counters, and a delta across the two widths
 * is garbage (a huge spike), so the poller skips the rate on the tick the width changes.
 * Nullable, no default, so it's a metadata-only change on a big table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interfaces', function (Blueprint $table) {
            $table->boolean('last_counter32')->nullable()->after('last_ts');
        });
    }

    public function down(): void
    {
        Schema::table('interfaces', function (Blueprint $table) {
            $table->dropColumn('last_counter32');
        });
    }
};
