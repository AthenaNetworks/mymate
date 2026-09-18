<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `devices.site_id` was added as a constrained foreign key but never indexed, and Postgres
 * (unlike MySQL) does not index FK columns for you. Every place that groups or counts devices
 * by site - `GET /api/sites` with its per-site device / down counts is the hot one, since the
 * geo map's site markers wait on it - was a full scan of `devices` per site. On a 25k-device,
 * 2.7k-site fleet that was ~60 s per call and routinely tripped the proxy timeout.
 *
 * (site_id, status) covers both the plain count and the `status = 'down'` count.
 *
 * Guarded with hasIndex so an operator who already built it live (CREATE INDEX CONCURRENTLY,
 * same name) is not failed by the migration.
 */
return new class extends Migration
{
    private const INDEX = 'devices_site_id_status_index';

    public function up(): void
    {
        if (Schema::hasIndex('devices', self::INDEX)) {
            return;
        }

        Schema::table('devices', function (Blueprint $table) {
            $table->index(['site_id', 'status'], self::INDEX);
        });
    }

    public function down(): void
    {
        if (! Schema::hasIndex('devices', self::INDEX)) {
            return;
        }

        Schema::table('devices', function (Blueprint $table) {
            $table->dropIndex(self::INDEX);
        });
    }
};
