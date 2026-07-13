<?php

namespace App\Actions\Import;

use App\Support\EngineLog;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Run the reverse-engineering script (scripts/dude-extract.py) against an uploaded
 * dude.db, producing CSVs in a fresh export directory. The script owns the binary
 * blob decoding; this Action just drives it and returns where the CSVs landed.
 *
 * Kept separate from {@see ImportDudeDatabase} so the import (CSV -> DB) is testable
 * with fixture CSVs, no Python/SQLite required.
 */
class ExtractDudeDatabase
{
    /**
     * @param  string  $dbPath  absolute path to the uploaded dude.db
     * @param  bool  $withCharts  pass --charts (time-series; large + slow)
     * @param  int|null  $timeout  extraction time limit (s); null uses the config default
     * @return string absolute path to the export directory holding the CSVs
     */
    public function __invoke(string $dbPath, bool $withCharts = true, ?int $timeout = null): string
    {
        if (! is_file($dbPath)) {
            throw new RuntimeException("Dude database not found: {$dbPath}");
        }

        $python = (string) config('mymate.import.python', 'python3');
        $script = base_path((string) config('mymate.import.extract_script', 'scripts/dude-extract.py'));
        if (! is_file($script)) {
            throw new RuntimeException("Extractor script not found: {$script}");
        }

        // The script writes ./export relative to its CWD - give each run its own dir.
        $workDir = dirname($dbPath);
        $exportDir = $workDir.'/export';

        $args = [$python, $script, $dbPath];
        if ($withCharts) {
            $args[] = '--charts';
        }

        $process = new Process($args, cwd: $workDir);
        $process->setTimeout((float) ($timeout ?? config('mymate.import.extract_timeout', 1800)));
        $process->run();

        if (! $process->isSuccessful()) {
            EngineLog::warning('import: extraction failed', [
                'exit' => $process->getExitCode(),
                'stderr' => mb_substr($process->getErrorOutput(), 0, 2000),
            ]);
            throw new RuntimeException('dude-extract.py failed: '.trim($process->getErrorOutput()));
        }

        if (! is_dir($exportDir) || ! is_file($exportDir.'/devices.csv')) {
            throw new RuntimeException('Extraction produced no CSVs in '.$exportDir);
        }

        EngineLog::debug('import: extraction complete', ['export' => $exportDir]);

        return $exportDir;
    }
}
