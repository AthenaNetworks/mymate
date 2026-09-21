<?php

namespace Tests\Feature;

use App\Enums\DeviceStatus;
use App\Models\Device;
use App\Models\Site;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `devices (site_id, status)` is what keeps `GET /api/sites` off a per-site full scan, so the
 * point of these tests is not that the migration ran - it is that the index it leaves behind is
 * one the planner will actually use.
 *
 * DatabaseMigrations rather than RefreshDatabase: CREATE INDEX CONCURRENTLY cannot run inside a
 * transaction block, and the interrupted-build case below needs a real one.
 */
class DeviceSiteIndexTest extends TestCase
{
    use DatabaseMigrations;

    private const INDEX = 'devices_site_id_status_index';

    public function test_the_migration_leaves_a_live_index_on_site_id_and_status(): void
    {
        $this->assertTrue($this->indexValidity());
        $this->assertSame('site_id,status', $this->indexColumns());
    }

    public function test_it_skips_an_index_an_operator_already_built(): void
    {
        $this->migration()->up(); // second run, index already live

        $this->assertTrue($this->indexValidity());
        $this->assertSame('site_id,status', $this->indexColumns());
    }

    public function test_it_rebuilds_an_invalid_index_left_by_an_interrupted_concurrent_build(): void
    {
        $this->leaveAnInvalidIndex();

        // The trap this guards: Postgres keeps a half-built index in the catalogue with
        // indisvalid = false and the planner ignores it, but Laravel's compileIndexes has no
        // indisvalid filter - so a hasIndex() guard sees a real index, skips the build, and
        // records the migration as applied over a table that is still being scanned in full.
        $this->assertTrue(Schema::hasIndex('devices', self::INDEX));
        $this->assertFalse($this->indexValidity());

        $this->migration()->up();

        $this->assertTrue($this->indexValidity());
        $this->assertSame('site_id,status', $this->indexColumns());
    }

    public function test_down_drops_the_index_and_any_half_built_leftover(): void
    {
        $this->migration()->down();
        $this->assertNull($this->indexValidity());

        $this->leaveAnInvalidIndex();
        $this->migration()->down();
        $this->assertNull($this->indexValidity());
    }

    /**
     * The only way to make Postgres keep a half-built index: a CONCURRENTLY build that fails
     * after its catalogue entry exists. A unique build over duplicate rows does exactly that.
     */
    private function leaveAnInvalidIndex(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::INDEX);

        $site = Site::factory()->create();
        Device::factory()->count(2)->create(['site_id' => $site->id, 'status' => DeviceStatus::Down]);

        try {
            DB::statement('CREATE UNIQUE INDEX CONCURRENTLY '.self::INDEX.' ON devices (site_id, status)');
            $this->fail('expected the concurrent unique build to fail over the duplicate rows');
        } catch (QueryException) {
            // expected - and the corpse it leaves is the point
        }
    }

    private function migration(): object
    {
        // require (not require_once) so each call re-evaluates to a fresh anonymous class.
        return require database_path('migrations/2026_09_18_000001_add_site_id_status_index_to_devices.php');
    }

    /** null when absent, true when live, false when an invalid leftover. */
    private function indexValidity(): ?bool
    {
        $row = DB::selectOne(
            'select i.indisvalid from pg_index i '
            .'join pg_class ic on ic.oid = i.indexrelid '
            .'join pg_class tc on tc.oid = i.indrelid '
            .'where ic.relname = ? and tc.relname = ?',
            [self::INDEX, 'devices']
        );

        return $row === null ? null : (bool) $row->indisvalid;
    }

    private function indexColumns(): ?string
    {
        $row = DB::selectOne(
            "select string_agg(a.attname, ',' order by k.ord) as cols from pg_index i "
            .'join pg_class ic on ic.oid = i.indexrelid '
            .'join lateral unnest(i.indkey) with ordinality as k(num, ord) on true '
            .'join pg_attribute a on a.attrelid = i.indrelid and a.attnum = k.num '
            .'where ic.relname = ? group by ic.relname',
            [self::INDEX]
        );

        return $row?->cols;
    }
}
