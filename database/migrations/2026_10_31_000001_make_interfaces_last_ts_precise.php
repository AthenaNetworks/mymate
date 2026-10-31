<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `interfaces.last_ts` is the time of the last SNMP counter read, the denominator of every rate.
 * It was whole seconds, so each rate came out up to a second's worth (~8% on a 12s poll) low.
 * Widening timestamp(0) -> timestamp(6) needs no table rewrite in Postgres.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE interfaces ALTER COLUMN last_ts TYPE timestamp(6) without time zone');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE interfaces ALTER COLUMN last_ts TYPE timestamp(0) without time zone');
    }
};
