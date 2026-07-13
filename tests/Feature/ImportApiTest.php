<?php

namespace Tests\Feature;

use App\Enums\ImportStatus;
use App\Jobs\RunDudeImportJob;
use App\Models\ImportRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The Dude import HTTP surface (FR-Dude): upload + validate, dispatch the job, poll
 * status, and cancel.
 */
class ImportApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsUser();
    }

    private function sqliteUpload(): UploadedFile
    {
        // A valid dude.db starts with the SQLite magic header - the controller sniffs it.
        return UploadedFile::fake()->createWithContent('dude.db', "SQLite format 3\000rest-of-file");
    }

    public function test_requires_authentication(): void
    {
        // Fresh app instance with no acting user.
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/imports', [])->assertUnauthorized();
    }

    public function test_uploading_a_database_dispatches_the_import_job(): void
    {
        Queue::fake();
        Storage::fake('local');

        $response = $this->postJson('/api/imports', [
            'database' => $this->sqliteUpload(),
            'mode' => 'upsert',
            'include_history' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.mode', 'upsert');

        $run = ImportRun::firstOrFail();
        $this->assertSame('dude.db', $run->original_filename);
        Storage::disk('local')->assertExists($run->stored_path);
        Queue::assertPushedOn('import', RunDudeImportJob::class);
    }

    public function test_uploading_accepts_a_custom_extraction_timeout(): void
    {
        Queue::fake();
        Storage::fake('local');

        $this->postJson('/api/imports', [
            'database' => $this->sqliteUpload(),
            'mode' => 'upsert',
            'extract_timeout' => 5400, // 90 minutes for a big-history import
        ])->assertCreated()->assertJsonPath('data.extract_timeout', 5400);

        $this->assertSame(5400, ImportRun::firstOrFail()->extract_timeout);
    }

    public function test_omitting_the_extraction_timeout_leaves_it_null_for_the_config_default(): void
    {
        Queue::fake();
        Storage::fake('local');

        $this->postJson('/api/imports', [
            'database' => $this->sqliteUpload(),
            'mode' => 'upsert',
        ])->assertCreated()->assertJsonPath('data.extract_timeout', null);

        $this->assertNull(ImportRun::firstOrFail()->extract_timeout);
    }

    public function test_rejects_an_out_of_range_extraction_timeout(): void
    {
        Queue::fake();
        Storage::fake('local');

        $this->postJson('/api/imports', [
            'database' => $this->sqliteUpload(),
            'mode' => 'upsert',
            'extract_timeout' => 999999, // beyond the 4h cap
        ])->assertStatus(422)->assertJsonValidationErrors('extract_timeout');

        Queue::assertNothingPushed();
    }

    public function test_dispatched_job_finds_the_stored_upload_and_completes(): void
    {
        // Regression: the upload is stored on the `local` disk (root storage/app/private
        // in Laravel 11+), but the job used to resolve it as storage_path('app/'.$path),
        // missing the /private segment -> "Dude database not found" on every web upload.
        // This runs the REAL job against a file stored exactly as the controller stores it.
        if (trim((string) shell_exec('command -v python3')) === '') {
            $this->markTestSkipped('python3 not available');
        }

        Storage::fake('local');
        $stored = 'imports/'.\Illuminate\Support\Str::uuid().'/dude.db';
        Storage::disk('local')->put($stored, file_get_contents(base_path('tests/Fixtures/dude-db/full.db')));

        $run = ImportRun::create([
            'original_filename' => 'dude.db', 'stored_path' => $stored,
            'mode' => 'upsert', 'include_history' => true, 'status' => ImportStatus::Pending->value,
        ]);

        app(RunDudeImportJob::class, ['importRunId' => $run->id])->handle(
            app(\App\Actions\Import\ExtractDudeDatabase::class),
            app(\App\Actions\Import\ImportDudeDatabase::class),
        );

        $run->refresh();
        $this->assertSame(ImportStatus::Completed, $run->status, 'import error: '.$run->error);
        $this->assertSame(2, \App\Models\Device::count()); // router1 + switch1 from the fixture
    }

    public function test_rejects_a_non_sqlite_file(): void
    {
        Queue::fake();
        Storage::fake('local');

        $this->postJson('/api/imports', [
            'database' => UploadedFile::fake()->createWithContent('notes.txt', 'just some text'),
            'mode' => 'upsert',
        ])->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_rejects_an_invalid_mode(): void
    {
        Storage::fake('local');

        $this->postJson('/api/imports', [
            'database' => $this->sqliteUpload(),
            'mode' => 'destroy-everything',
        ])->assertStatus(422)->assertJsonValidationErrors('mode');
    }

    public function test_cancel_flags_a_running_import(): void
    {
        $run = ImportRun::create([
            'original_filename' => 'dude.db', 'stored_path' => 'imports/x/dude.db',
            'mode' => 'upsert', 'include_history' => true, 'status' => ImportStatus::Importing->value,
        ]);

        $this->postJson("/api/imports/{$run->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.cancel_requested', true)
            ->assertJsonPath('data.status', 'importing'); // job will flip to cancelled at its checkpoint

        $this->assertTrue($run->fresh()->cancel_requested);
    }

    public function test_cancel_of_a_pending_import_is_immediate(): void
    {
        $run = ImportRun::create([
            'original_filename' => 'dude.db', 'stored_path' => 'imports/x/dude.db',
            'mode' => 'upsert', 'include_history' => true, 'status' => ImportStatus::Pending->value,
        ]);

        $this->postJson("/api/imports/{$run->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_show_returns_progress(): void
    {
        $run = ImportRun::create([
            'original_filename' => 'dude.db', 'stored_path' => 'imports/x/dude.db',
            'mode' => 'upsert', 'include_history' => true, 'status' => ImportStatus::Importing->value,
            'stage' => 'Importing history',
            'progress' => ['percent' => 42.5, 'detail' => '1,000 samples imported', 'eta_seconds' => 30],
        ]);

        $this->getJson("/api/imports/{$run->id}")
            ->assertOk()
            ->assertJsonPath('data.stage', 'Importing history')
            ->assertJsonPath('data.progress.percent', 42.5)
            ->assertJsonPath('data.progress.eta_seconds', 30);
    }
}
