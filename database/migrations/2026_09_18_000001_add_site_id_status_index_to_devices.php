<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
 * Guarded so an operator who already built it live (CREATE INDEX CONCURRENTLY, same name) is
 * not failed by the migration. The guard reads `pg_index.indisvalid` rather than asking
 * `Schema::hasIndex`, because a CREATE INDEX CONCURRENTLY that fails part-way leaves the index
 * in the catalogue with indisvalid = false - present by name, ignored by the planner. Laravel's
 * Postgres `compileIndexes` has no indisvalid filter, so hasIndex() reports that corpse as a
 * real index and we would skip the build, record the migration as applied, and quietly keep
 * every full scan this migration exists to remove. Drop the corpse and build for real instead.
 */
return new class extends Migration
{
    private const INDEX = 'devices_site_id_status_index';

    public function up(): void
    {
        $state = $this->indexState();

        if ($state === true) {
            return; // already built and usable - an operator got there first
        }

        if ($state === false) {
            DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
        }

        Schema::table('devices', function (Blueprint $table) {
            $table->index(['site_id', 'status'], self::INDEX);
        });
    }

    public function down(): void
    {
        // Drops a half-built leftover too: the post-rollback state is "no such index".
        DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
    }

    /**
     * null when the index is absent, true when it is live, false when it is an invalid
     * leftover from an interrupted concurrent build.
     */
    private function indexState(): ?bool
    {
        $row = DB::selectOne(
            'select i.indisvalid from pg_index i '
            .'join pg_class ic on ic.oid = i.indexrelid '
            .'join pg_class tc on tc.oid = i.indrelid '
            .'join pg_namespace tn on tn.oid = tc.relnamespace '
            .'where ic.relname = ? and tc.relname = ? and tn.nspname = current_schema()',
            [self::INDEX, 'devices']
        );

        return $row === null ? null : (bool) $row->indisvalid;
    }
};
