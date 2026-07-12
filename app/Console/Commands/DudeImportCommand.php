<?php

namespace App\Console\Commands;

use App\Actions\Import\ExtractDudeDatabase;
use App\Actions\Import\ImportDudeDatabase;
use App\Enums\ImportMode;
use App\Enums\ImportStatus;
use App\Models\ImportRun;
use App\Support\ImportProgress;
use Illuminate\Console\Command;

/**
 * CLI entry point for a MikroTik "The Dude" import (FR-Dude) - the same Extract +
 * Import pipeline the web upload uses, run inline so ops can import a dude.db from
 * the shell. Mirrors the UI options: --mode and --no-history.
 */
class DudeImportCommand extends Command
{
    protected $signature = 'mymate:dude-import
        {file : Path to the dude.db SQLite file}
        {--mode=upsert : upsert (keep existing, update-or-insert) or fresh (wipe first)}
        {--no-history : Skip chart_values history (config only - much faster)}';

    protected $description = 'Import a MikroTik "The Dude" database (devices, interfaces, links, maps, history).';

    public function handle(ExtractDudeDatabase $extract, ImportDudeDatabase $import): int
    {
        $file = (string) $this->argument('file');
        $resolved = realpath($file);
        if ($resolved === false || ! is_file($resolved)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }
        $file = $resolved; // absolute - the extractor runs with its own cwd

        $mode = ImportMode::tryFrom((string) $this->option('mode'));
        if ($mode === null) {
            $this->error('Invalid --mode (expected upsert or fresh).');

            return self::FAILURE;
        }

        $run = ImportRun::create([
            'original_filename' => basename($file),
            'stored_path' => $file,
            'mode' => $mode->value,
            'include_history' => ! $this->option('no-history'),
            'status' => ImportStatus::Extracting->value,
            'started_at' => now(),
        ]);

        $this->info("Extracting CSVs from {$file} ...");
        $t0 = microtime(true);

        try {
            $exportDir = $extract($file, $run->include_history);
            $run->markStatus(ImportStatus::Importing);

            $this->info('Importing into My Mate ('.$mode->value.($run->include_history ? ', with history' : '').') ...');
            $summary = $import($run, $exportDir, new ImportProgress($run));

            $run->forceFill(['status' => ImportStatus::Completed, 'summary' => $summary, 'finished_at' => now()])->save();
        } catch (\Throwable $e) {
            $run->forceFill(['status' => ImportStatus::Failed, 'error' => $e->getMessage(), 'finished_at' => now()])->save();
            $this->error('Import failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $elapsed = round(microtime(true) - $t0, 1);
        $this->newLine();
        $this->info("Import complete in {$elapsed}s:");
        foreach ($summary as $key => $val) {
            $this->line('  '.str_pad($key, 22).' '.(is_array($val) ? json_encode($val) : (string) $val));
        }

        return self::SUCCESS;
    }
}
