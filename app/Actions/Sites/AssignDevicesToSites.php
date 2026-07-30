<?php

namespace App\Actions\Sites;

use App\Models\Device;
use App\Models\Site;
use App\Support\CsvReader;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Put devices at sites, by either of the two things an operator actually has.
 *
 * MAPPING (authoritative): a CSV of `mgmt_ip,external_ref` straight out of the OSS that
 * already knows which tower each radio is on. This is the accurate path - the OSS was told
 * the truth by whoever installed the gear.
 *
 * NEAREST (derived): for devices that carry their own coordinates (a pin someone dropped, or
 * an SNMP sysLocation the poller parsed) but no site, snap to the closest site inside a
 * radius. Useful for filling gaps, but it is a guess: a backhaul radio at a tower half a mile
 * from a subscriber can land at the wrong one, so the radius defaults tight and anything
 * outside it is left unassigned rather than forced.
 *
 * Both passes skip `site_source = manual`, so an operator's own placement is never
 * overwritten by a re-run.
 */
class AssignDevicesToSites
{
    /** Default snap radius for the nearest-site pass, in kilometres. */
    public const DEFAULT_RADIUS_KM = 0.5;

    /**
     * Assign from an authoritative `mgmt_ip,external_ref` CSV.
     *
     * @return array{assigned: int, unmatched_ip: int, unknown_site: int, skipped_manual: int}
     */
    public function fromMapping(string $csvPath): array
    {
        if (! is_file($csvPath)) {
            throw new RuntimeException("Mapping CSV not found: {$csvPath}");
        }

        // Both sides in memory: a fleet's devices and sites are thousands of rows, not
        // millions, and this turns the whole pass into one query each plus one update per hit.
        $devicesByIp = Device::query()->get(['id', 'mgmt_ip', 'site_source'])->keyBy('mgmt_ip');
        $siteIdByRef = Site::query()->whereNotNull('external_ref')->pluck('id', 'external_ref');

        $summary = ['assigned' => 0, 'unmatched_ip' => 0, 'unknown_site' => 0, 'skipped_manual' => 0];

        DB::transaction(function () use ($csvPath, $devicesByIp, $siteIdByRef, &$summary): void {
            foreach (CsvReader::rows($csvPath) as $row) {
                $ip = trim((string) ($row['mgmt_ip'] ?? ''));
                $ref = trim((string) ($row['external_ref'] ?? ''));
                if ($ip === '' || $ref === '') {
                    continue;
                }

                $device = $devicesByIp->get($ip);
                if ($device === null) {
                    $summary['unmatched_ip']++;

                    continue;
                }
                if ($device->site_source === 'manual') {
                    $summary['skipped_manual']++;

                    continue;
                }

                $siteId = $siteIdByRef->get($ref);
                if ($siteId === null) {
                    $summary['unknown_site']++;

                    continue;
                }

                Device::whereKey($device->id)->update(['site_id' => $siteId, 'site_source' => 'import']);
                $summary['assigned']++;
            }
        });

        return $summary;
    }

    /**
     * Snap unassigned devices that have their own coordinates to the nearest site.
     *
     * @param  float  $radiusKm  devices with no site inside this radius are left unassigned
     * @return array{assigned: int, too_far: int, considered: int}
     */
    public function byNearest(float $radiusKm = self::DEFAULT_RADIUS_KM): array
    {
        $sites = Site::query()
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->get(['id', 'latitude', 'longitude']);

        $summary = ['assigned' => 0, 'too_far' => 0, 'considered' => 0];
        if ($sites->isEmpty()) {
            return $summary;
        }

        Device::query()
            ->whereNull('site_id')
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->where(fn ($q) => $q->whereNull('site_source')->orWhere('site_source', '!=', 'manual'))
            ->chunkById(500, function ($devices) use ($sites, $radiusKm, &$summary): void {
                foreach ($devices as $device) {
                    $summary['considered']++;

                    $best = null;
                    $bestKm = INF;
                    foreach ($sites as $site) {
                        $km = $site->distanceKmTo($device->latitude, $device->longitude);
                        if ($km !== null && $km < $bestKm) {
                            $bestKm = $km;
                            $best = $site;
                        }
                    }

                    if ($best === null || $bestKm > $radiusKm) {
                        $summary['too_far']++;

                        continue;
                    }

                    Device::whereKey($device->id)->update(['site_id' => $best->id, 'site_source' => 'nearest']);
                    $summary['assigned']++;
                }
            });

        return $summary;
    }
}
