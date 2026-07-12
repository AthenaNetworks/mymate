<?php

namespace App\Support;

use Generator;
use RuntimeException;

/**
 * Minimal streaming CSV reader: yields each row as an associative array keyed by the
 * header. Streams line-by-line so the multi-million-row chart_values files never load
 * into memory at once (used by the Dude importer).
 */
class CsvReader
{
    /**
     * @return Generator<int, array<string, string>>
     */
    public static function rows(string $path): Generator
    {
        $fh = @fopen($path, 'r');
        if ($fh === false) {
            throw new RuntimeException("Cannot open CSV: {$path}");
        }

        try {
            $header = fgetcsv($fh);
            if ($header === false || $header === null) {
                return; // empty file -> no rows
            }
            $header = array_map(static fn ($h): string => (string) $h, $header);
            $width = count($header);

            while (($row = fgetcsv($fh)) !== false) {
                if ($row === [null] || $row === []) {
                    continue; // blank line
                }
                // Pad/trim so array_combine never throws on a short/long row.
                $row = array_slice(array_pad($row, $width, ''), 0, $width);
                yield array_combine($header, array_map(static fn ($v): string => (string) ($v ?? ''), $row));
            }
        } finally {
            fclose($fh);
        }
    }

    /**
     * Like {@see rows()} but yields [row, byteOffset] so a caller can report
     * progress/ETA against the file size without a pre-count.
     *
     * @return Generator<int, array{0: array<string,string>, 1: int}>
     */
    public static function rowsTell(string $path): Generator
    {
        $fh = @fopen($path, 'r');
        if ($fh === false) {
            throw new RuntimeException("Cannot open CSV: {$path}");
        }

        try {
            $header = fgetcsv($fh);
            if ($header === false || $header === null) {
                return;
            }
            $header = array_map(static fn ($h): string => (string) $h, $header);
            $width = count($header);

            while (($row = fgetcsv($fh)) !== false) {
                if ($row === [null] || $row === []) {
                    continue;
                }
                $row = array_slice(array_pad($row, $width, ''), 0, $width);
                yield [array_combine($header, array_map(static fn ($v): string => (string) ($v ?? ''), $row)), (int) ftell($fh)];
            }
        } finally {
            fclose($fh);
        }
    }

    /** True when the CSV exists and is readable. */
    public static function exists(string $path): bool
    {
        return is_file($path) && is_readable($path);
    }
}
