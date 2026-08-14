<?php

namespace App\Actions\Sites;

use App\Enums\SiteKind;
use App\Models\Site;
use App\Support\CsvReader;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Upsert sites from a CSV export of whatever system owns the truth (an OSS/inventory, a NetBox
 * dump, a spreadsheet).
 *
 * Deliberately a plain CSV rather than an integration with any one OSS: every operator has a
 * different inventory, and a re-runnable "here are my sites" file is the one thing they can
 * all produce. `external_ref` is the upsert key, so this is safe to run on a schedule - a
 * corrected coordinate in the source flows through on the next run and every device at the
 * site follows it, because devices inherit rather than copy.
 *
 * Required columns: name. Optional: external_ref, kind, latitude, longitude, address, note.
 * A row with no external_ref is matched on name instead, so a hand-written file works too.
 */
class ImportSites
{
    /**
     * @return array{created: int, updated: int, skipped: int}
     */
    public function __invoke(string $csvPath): array
    {
        if (! is_file($csvPath)) {
            throw new RuntimeException("Sites CSV not found: {$csvPath}");
        }

        $summary = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        DB::transaction(function () use ($csvPath, &$summary): void {
            foreach (CsvReader::rows($csvPath) as $row) {
                $name = trim((string) ($row['name'] ?? ''));
                if ($name === '') {
                    $summary['skipped']++;

                    continue;
                }

                $ref = trim((string) ($row['external_ref'] ?? ''));
                $site = $ref !== ''
                    ? Site::firstOrNew(['external_ref' => $ref])
                    : Site::firstOrNew(['name' => $name]);

                $existed = $site->exists;

                $site->name = $name;
                $site->kind = SiteKind::parse($row['kind'] ?? null);
                $site->latitude = $this->coord($row['latitude'] ?? null, 90);
                $site->longitude = $this->coord($row['longitude'] ?? null, 180);
                $site->address = $this->nullableString($row['address'] ?? null);
                $site->note = $this->nullableString($row['note'] ?? null);
                if ($ref !== '') {
                    $site->external_ref = $ref;
                }
                $site->save();

                $summary[$existed ? 'updated' : 'created']++;
            }
        });

        return $summary;
    }

    /**
     * A blank or out-of-range coordinate becomes null rather than an error - inventory
     * exports routinely carry empty or 0,0 rows for sites nobody has surveyed yet, and one
     * of those should not fail an import of thousands of good ones.
     */
    private function coord(mixed $value, float $limit): ?float
    {
        $raw = trim((string) $value);
        if ($raw === '' || ! is_numeric($raw)) {
            return null;
        }

        $num = (float) $raw;

        return abs($num) <= $limit ? $num : null;
    }

    private function nullableString(mixed $value): ?string
    {
        $str = trim((string) $value);

        return $str === '' ? null : $str;
    }
}
