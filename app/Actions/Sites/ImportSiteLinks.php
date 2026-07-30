<?php

namespace App\Actions\Sites;

use App\Models\Site;
use App\Models\SiteLink;
use App\Support\CsvReader;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Upsert backhaul links between sites from a CSV out of the OSS that owns the backbone topology.
 * Columns: site_a_ref, site_b_ref (both the sites' external_ref, i.e. the OSS id), optional
 * media_type. Idempotent on a stable external_ref built from the pair, so re-running against
 * the source is a safe re-sync.
 */
class ImportSiteLinks
{
    /**
     * @return array{created: int, updated: int, skipped: int}
     */
    public function __invoke(string $csvPath): array
    {
        if (! is_file($csvPath)) {
            throw new RuntimeException("Site-links CSV not found: {$csvPath}");
        }

        $siteIdByRef = Site::query()->whereNotNull('external_ref')->pluck('id', 'external_ref');
        $summary = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        DB::transaction(function () use ($csvPath, $siteIdByRef, &$summary): void {
            foreach (CsvReader::rows($csvPath) as $row) {
                $aRef = trim((string) ($row['site_a_ref'] ?? ''));
                $bRef = trim((string) ($row['site_b_ref'] ?? ''));
                $a = $siteIdByRef->get($aRef);
                $b = $siteIdByRef->get($bRef);
                // Both ends must resolve to a known site, and a link to itself is meaningless.
                if ($a === null || $b === null || $a === $b) {
                    $summary['skipped']++;

                    continue;
                }

                // Order-independent key so A-B and B-A are the same link.
                $ref = 'pair:'.min($a, $b).'-'.max($a, $b);
                $link = SiteLink::firstOrNew(['external_ref' => $ref]);
                $existed = $link->exists;
                $link->site_a_id = $a;
                $link->site_b_id = $b;
                $link->media_type = ($m = trim((string) ($row['media_type'] ?? ''))) !== '' ? $m : null;
                $link->save();

                $summary[$existed ? 'updated' : 'created']++;
            }
        });

        return $summary;
    }
}
