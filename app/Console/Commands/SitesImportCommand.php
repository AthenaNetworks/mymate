<?php

namespace App\Console\Commands;

use App\Actions\Sites\AssignDevicesToSites;
use App\Actions\Sites\ImportSiteLinks;
use App\Actions\Sites\ImportSites;
use Illuminate\Console\Command;

/**
 * Load sites from a CSV and (optionally) place devices at them in one shot - the shell entry
 * point ops use to sync an inventory. Safe to re-run: sites upsert on external_ref and the
 * device passes never touch a manual placement.
 */
class SitesImportCommand extends Command
{
    protected $signature = 'mymate:sites:import
        {file : CSV of sites (columns: name[,external_ref,kind,latitude,longitude,address,note])}
        {--map= : also assign devices from a mgmt_ip,external_ref CSV (authoritative)}
        {--links= : also import site-to-site backhauls from a site_a_ref,site_b_ref[,media_type] CSV}
        {--nearest : also snap unassigned devices with their own coordinates to the closest site}
        {--radius=0.5 : nearest-site snap radius in kilometres}';

    protected $description = 'Import sites from CSV, and optionally assign devices + import backhauls.';

    public function handle(ImportSites $importSites, AssignDevicesToSites $assign, ImportSiteLinks $importLinks): int
    {
        $file = (string) $this->argument('file');
        if (! is_file($file)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }

        $this->info("Importing sites from {$file} ...");
        $sites = $importSites($file);
        $this->line(sprintf('  sites: %d created, %d updated, %d skipped',
            $sites['created'], $sites['updated'], $sites['skipped']));

        if (($map = $this->option('map')) !== null) {
            if (! is_file((string) $map)) {
                $this->error("Mapping file not found: {$map}");

                return self::FAILURE;
            }
            $this->info("Assigning devices from mapping {$map} ...");
            $m = $assign->fromMapping((string) $map);
            $this->line(sprintf('  mapping: %d assigned, %d ip-unmatched, %d unknown-site, %d manual-kept',
                $m['assigned'], $m['unmatched_ip'], $m['unknown_site'], $m['skipped_manual']));
        }

        if (($links = $this->option('links')) !== null) {
            if (! is_file((string) $links)) {
                $this->error("Links file not found: {$links}");

                return self::FAILURE;
            }
            $this->info("Importing site backhauls from {$links} ...");
            $l = $importLinks((string) $links);
            $this->line(sprintf('  backhauls: %d created, %d updated, %d skipped',
                $l['created'], $l['updated'], $l['skipped']));
        }

        if ($this->option('nearest')) {
            $radius = max(0.0, (float) $this->option('radius'));
            $this->info("Snapping unassigned devices to nearest site (<= {$radius} km) ...");
            $n = $assign->byNearest($radius);
            $this->line(sprintf('  nearest: %d assigned, %d too-far, %d considered',
                $n['assigned'], $n['too_far'], $n['considered']));
        }

        return self::SUCCESS;
    }
}
