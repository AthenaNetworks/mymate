<?php

namespace Tests\Feature;

use App\Actions\Import\ExtractDudeDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The Python extraction stage ({@see ExtractDudeDatabase} -> scripts/dude-extract.py).
 *
 * DudeImportTest exercises the CSV -> DB mapping against fixture CSVs and never runs
 * Python, so it can't catch a crash in the binary/SQLite decoding. This drives the
 * real script against small committed dude.db fixtures (built by
 * tests/Fixtures/dude-db/make-fixtures.py).
 *
 * Regression (Dave's DB): the `outages` log and the `chart_values_*` history tables
 * are OPTIONAL in The Dude - a server with charting/outage-history disabled (or a
 * different Dude version) simply won't have them. The extractor used to query them
 * unconditionally and die with `sqlite3.OperationalError: no such table`, failing the
 * whole import even though every device/map/link had already extracted cleanly.
 */
class ExtractDudeDatabaseTest extends TestCase
{
    private function extractWorkDir(string $fixture): string
    {
        $python = trim((string) shell_exec('command -v python3'));
        if ($python === '') {
            $this->markTestSkipped('python3 not available');
        }

        // The script writes ./export next to the db, so give the run its own dir.
        $dir = sys_get_temp_dir().'/dude-extract-test-'.Str::random(8);
        mkdir($dir, 0755, true);
        copy(base_path('tests/Fixtures/dude-db/'.$fixture), $dir.'/dude.db');

        return $dir;
    }

    public function test_extracts_a_complete_database(): void
    {
        $dir = $this->extractWorkDir('full.db');

        $exportDir = app(ExtractDudeDatabase::class)($dir.'/dude.db', withCharts: true);

        $this->assertFileExists($exportDir.'/devices.csv');
        // Two devices, the outage row and the raw chart table all came through.
        $this->assertStringContainsString('router1', file_get_contents($exportDir.'/devices.csv'));
        $this->assertFileExists($exportDir.'/outages.csv');
        $this->assertFileExists($exportDir.'/chart_values_raw.csv');

        $this->deleteDir($dir);
    }

    public function test_extraction_survives_a_database_missing_the_optional_tables(): void
    {
        // The reproduction of Dave's failure: no `outages`, no `chart_values_*` tables.
        $dir = $this->extractWorkDir('no-optional-tables.db');

        // Must NOT throw - before the fix this raised "dude-extract.py failed:
        // sqlite3.OperationalError: no such table: outages" and the import died.
        $exportDir = app(ExtractDudeDatabase::class)($dir.'/dude.db', withCharts: true);

        // The config CSVs still extract fully.
        $this->assertFileExists($exportDir.'/devices.csv');
        $this->assertStringContainsString('router1', file_get_contents($exportDir.'/devices.csv'));
        // The missing chart tables are simply skipped, not written.
        $this->assertFileDoesNotExist($exportDir.'/chart_values_raw.csv');

        $this->deleteDir($dir);
    }

    private function deleteDir(string $dir): void
    {
        foreach (glob($dir.'/{,export/}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($dir.'/export');
        @rmdir($dir);
    }
}
