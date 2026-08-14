<?php

namespace Tests\Feature;

use App\Actions\Sites\AssignDevicesToSites;
use App\Actions\Sites\ImportSites;
use App\Models\Device;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteImportAssignTest extends TestCase
{
    use RefreshDatabase;

    private function csv(string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mmcsv').'.csv';
        file_put_contents($path, $body);

        return $path;
    }

    public function test_import_upserts_sites_on_external_ref(): void
    {
        $import = app(ImportSites::class);

        $first = $import($this->csv("name,external_ref,kind,latitude,longitude\nNorth Tower,inv:1,tower,40.00,-100.00\n"));
        $this->assertSame(['created' => 1, 'updated' => 0, 'skipped' => 0], $first);

        // Same external_ref, corrected coordinate -> update in place, not a duplicate.
        $second = $import($this->csv("name,external_ref,kind,latitude,longitude\nNorth Tower West,inv:1,tower,40.01,-100.01\n"));
        $this->assertSame(['created' => 0, 'updated' => 1, 'skipped' => 0], $second);

        $this->assertDatabaseCount('sites', 1);
        $this->assertDatabaseHas('sites', ['external_ref' => 'inv:1', 'name' => 'North Tower West', 'latitude' => 40.01]);
    }

    public function test_import_skips_rows_with_no_name_and_nulls_bad_coordinates(): void
    {
        $summary = app(ImportSites::class)($this->csv(
            "name,external_ref,latitude,longitude\n,inv:x,1,2\nGood,inv:y,999,0\n"
        ));

        $this->assertSame(1, $summary['skipped']); // nameless row
        $this->assertSame(1, $summary['created']);
        $this->assertDatabaseHas('sites', ['external_ref' => 'inv:y', 'latitude' => null]); // 999 is out of range -> null
    }

    public function test_mapping_assignment_matches_device_ip_to_site_ref(): void
    {
        $site = Site::factory()->create(['external_ref' => 'inv:tower-9']);
        $device = Device::factory()->create(['mgmt_ip' => '10.0.2.42']);

        $summary = app(AssignDevicesToSites::class)->fromMapping(
            $this->csv("mgmt_ip,external_ref\n10.0.2.42,inv:tower-9\n10.0.2.99,inv:tower-9\n")
        );

        $this->assertSame(1, $summary['assigned']);
        $this->assertSame(1, $summary['unmatched_ip']); // .99 isn't a known device
        $this->assertDatabaseHas('devices', ['id' => $device->id, 'site_id' => $site->id, 'site_source' => 'import']);
    }

    public function test_mapping_never_overrides_a_manual_placement(): void
    {
        $manualSite = Site::factory()->create();
        $importSite = Site::factory()->create(['external_ref' => 'inv:other']);
        $device = Device::factory()->create([
            'mgmt_ip' => '10.0.2.42', 'site_id' => $manualSite->id, 'site_source' => 'manual',
        ]);

        $summary = app(AssignDevicesToSites::class)->fromMapping(
            $this->csv("mgmt_ip,external_ref\n10.0.2.42,inv:other\n")
        );

        $this->assertSame(1, $summary['skipped_manual']);
        $this->assertDatabaseHas('devices', ['id' => $device->id, 'site_id' => $manualSite->id]);
    }

    public function test_nearest_snaps_within_radius_and_leaves_far_devices_alone(): void
    {
        // Two sites ~11 km apart (0.1 deg latitude ~ 11.1 km).
        $near = Site::factory()->at(40.0000, -100.0000)->create();
        Site::factory()->at(40.1000, -100.0000)->create(); // ~11 km away

        // Device 300 m south of $near -> inside the default 0.5 km radius.
        $close = Device::factory()->create(['latitude' => 39.9973, 'longitude' => -100.0000]);
        // Device 5 km away from any site -> left unassigned.
        $far = Device::factory()->create(['latitude' => 40.0450, 'longitude' => -100.0000]);

        $summary = app(AssignDevicesToSites::class)->byNearest();

        $this->assertSame(1, $summary['assigned']);
        $this->assertSame(1, $summary['too_far']);
        $this->assertDatabaseHas('devices', ['id' => $close->id, 'site_id' => $near->id, 'site_source' => 'nearest']);
        $this->assertDatabaseHas('devices', ['id' => $far->id, 'site_id' => null]);
    }
}
