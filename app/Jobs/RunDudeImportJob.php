<?php

namespace App\Jobs;

use App\Actions\Import\ExtractDudeDatabase;
use App\Actions\Import\ImportDudeDatabase;
use App\Enums\ImportStatus;
use App\Exceptions\ImportCancelled;
use App\Models\ImportRun;
use App\Support\EngineLog;
use App\Support\ImportProgress;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Run one Dude import end-to-end off the request: extract CSVs (Python), then upsert
 * into My Mate. tries=1 - an import is not safe to blindly retry; failures surface on
 * the ImportRun row for the UI. Runs on the isolated `import` queue (heavy + slow).
 */
class RunDudeImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public int $importRunId) {}

    public function handle(ExtractDudeDatabase $extract, ImportDudeDatabase $import): void
    {
        $run = ImportRun::find($this->importRunId);
        if ($run === null || $run->status->isTerminal()) {
            return;
        }

        // A cancel that arrived while still queued - honour it before any work.
        if ($run->cancel_requested) {
            $run->forceFill(['status' => ImportStatus::Cancelled, 'finished_at' => now()])->save();
            $this->cleanup($run);

            return;
        }

        $progress = new ImportProgress($run);
        $run->forceFill([
            'status' => ImportStatus::Extracting, 'started_at' => now(),
            'stage' => 'Extracting CSVs from database',
            'progress' => ['percent' => 0, 'detail' => null, 'eta_seconds' => null],
        ])->save();

        try {
            // Resolve via the same disk the upload was stored on. The `local` disk root
            // is storage/app/private (Laravel 11+), so a naive storage_path('app/'.$path)
            // would miss the /private segment and fail with "Dude database not found".
            $exportDir = $extract(Storage::disk('local')->path($run->stored_path), $run->include_history);
            $progress->checkCancelled(); // can't interrupt Python, but stop before importing

            $run->markStatus(ImportStatus::Importing);
            $summary = $import($run, $exportDir, $progress);

            $run->forceFill([
                'status' => ImportStatus::Completed,
                'stage' => 'Completed',
                'progress' => ['percent' => 100, 'detail' => null, 'eta_seconds' => 0],
                'summary' => $summary,
                'finished_at' => now(),
            ])->save();

            EngineLog::debug('import: completed', ['run' => $run->id, 'summary' => $summary]);
            $this->cleanup($run);
        } catch (ImportCancelled) {
            // Clean stop: config changes rolled back inside the transaction; any
            // history rows already written are valid (non-corrupting) partial data.
            $run->forceFill([
                'status' => ImportStatus::Cancelled,
                'stage' => 'Cancelled',
                'finished_at' => now(),
            ])->save();
            EngineLog::debug('import: cancelled', ['run' => $run->id]);
            $this->cleanup($run);
        } catch (\Throwable $e) {
            $run->forceFill([
                'status' => ImportStatus::Failed,
                'stage' => 'Failed',
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'finished_at' => now(),
            ])->save();
            EngineLog::warning('import: failed', ['run' => $run->id, 'error' => $e->getMessage()]);

            throw $e;
        }
    }

    /** Remove the uploaded .db + extracted CSVs once the import has succeeded. */
    private function cleanup(ImportRun $run): void
    {
        $dbPath = Storage::disk('local')->path($run->stored_path);
        $dir = dirname($dbPath);
        if (is_dir($dir) && str_contains($dir, 'imports')) {
            $files = glob($dir.'/{,export/}*', GLOB_BRACE) ?: [];
            foreach ($files as $f) {
                if (is_file($f)) {
                    @unlink($f);
                }
            }
            @rmdir($dir.'/export');
            @rmdir($dir);
        }
    }
}
