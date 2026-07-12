<?php

namespace Tests\Feature;

use App\Actions\Import\ImportDudeDatabase;
use App\Enums\ImportMode;
use App\Enums\PollMethod;
use App\Exceptions\ImportCancelled;
use App\Models\Credential;
use App\Models\Device;
use App\Models\ImportRun;
use App\Models\Link;
use App\Models\Map;
use App\Models\NetworkInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The CSV -> My Mate conversion (FR-Dude). Runs ImportDudeDatabase against small
 * fixture CSVs (no Python/SQLite needed) so the mapping + upsert/fresh/cancel
 * behaviour is exercised directly.
 */
class DudeImportTest extends TestCase
{
    use RefreshDatabase;

    private string $fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtures = base_path('tests/Fixtures/dude-export');
        // Chart fixture timestamps sit just inside the "raw < 7d" band relative to this.
        Carbon::setTestNow('2026-06-22 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function doImport(ImportMode $mode = ImportMode::Upsert, bool $history = true): array
    {
        $run = ImportRun::create([
            'original_filename' => 'dude.db',
            'stored_path' => 'imports/x/dude.db',
            'mode' => $mode->value,
            'include_history' => $history,
            'status' => 'importing',
        ]);

        return app(ImportDudeDatabase::class)($run, $this->fixtures);
    }

    public function test_upsert_imports_the_full_topology(): void
    {
        $summary = $this->doImport();

        // Devices: 3 imported, keyed by mgmt_ip; parent + type + poll method mapped.
        $this->assertSame(3, Device::count());
        $router = Device::where('mgmt_ip', '10.0.0.1')->firstOrFail();
        $switch = Device::where('mgmt_ip', '10.0.0.2')->firstOrFail();
        $apiOnly = Device::where('mgmt_ip', '10.0.0.3')->firstOrFail();
        $this->assertSame('router', $router->device_type->value);
        $this->assertSame('switch', $switch->device_type->value);
        $this->assertSame($router->id, $switch->parent_device_id);

        // Poll method: SNMP when a working community exists, RouterOS when SNMP disabled.
        $this->assertSame(PollMethod::Snmp, $router->poll_method);
        $this->assertSame(PollMethod::RouterOs, $apiOnly->poll_method);

        // Credentials: one SNMP (from the profile) + one RouterOS (deduped login).
        $this->assertTrue(Credential::where('type', 'snmp')->where('name', 'v2-public')->exists());
        $this->assertTrue(Credential::where('type', 'routeros')->exists());
        $this->assertSame('public', Credential::where('name', 'v2-public')->first()->snmp_community);

        // Interfaces: the monitored ones (real if_index + speed).
        $ether1 = NetworkInterface::where('device_id', $router->id)->where('if_index', 1)->firstOrFail();
        $this->assertSame(1000, $ether1->speed_mbps); // 1e9 bps -> 1000 Mbps

        // Link: A end real interface, B end a best-effort stub on the peer.
        $this->assertSame(1, Link::count());
        $link = Link::first();
        $this->assertSame($ether1->id, $link->a_interface_id);
        $stub = NetworkInterface::find($link->b_interface_id);
        $this->assertSame($switch->id, $stub->device_id);
        $this->assertGreaterThanOrEqual(1_000_000, $stub->if_index); // stub index space

        // Map + placement + legacy map_x/y mirror (coords are normalised, not raw).
        $this->assertTrue(Map::where('name', 'Main')->exists());
        $this->assertDatabaseHas('device_map_positions', ['device_id' => $router->id]);
        $this->assertNotNull($router->fresh()->map_x);

        // Outage (closed, duration applied).
        $this->assertDatabaseHas('outages', ['device_id' => $router->id, 'duration_s' => 120]);

        // History: tx->bps_out, rx->bps_in half-rows for ether1 (2 ts x 2 dir = 4 rows).
        $samples = DB::table('interface_samples')->where('interface_id', $ether1->id)->get();
        $this->assertCount(4, $samples);
        $this->assertSame(4, $summary['history']['samples']);
        // util_in computed against 1000 Mbps: 250 Mbps / 1000 Mbps = 25%.
        $rx = DB::table('interface_samples')->where('interface_id', $ether1->id)->whereNotNull('bps_in')->first();
        $this->assertEqualsWithDelta(25.0, (float) $rx->util_in, 0.01);

        // Live util columns are seeded from the latest sample so the imported link shows
        // throughput immediately (latest rx=200 Mbps -> 20%, tx=400 Mbps -> 40% of 1000).
        $ether1->refresh();
        $this->assertEqualsWithDelta(20.0, (float) $ether1->util_in, 0.01);
        $this->assertEqualsWithDelta(40.0, (float) $ether1->util_out, 0.01);
        $this->assertSame(1, $summary['history']['seeded_util']); // one interface seeded (both directions)
    }

    public function test_placements_are_decompacted_so_nodes_do_not_overlap(): void
    {
        // The fixture packs 3 devices within ~30px (Dude icon spacing). After import
        // every pair must clear the My Mate node footprint (no AABB overlap).
        $this->doImport();

        $cfg = config('mymate.import.layout');
        $positions = DB::table('device_map_positions')->get(['x', 'y'])->all();
        $this->assertCount(3, $positions);

        for ($i = 0; $i < count($positions); $i++) {
            for ($j = $i + 1; $j < count($positions); $j++) {
                $dx = abs($positions[$i]->x - $positions[$j]->x);
                $dy = abs($positions[$i]->y - $positions[$j]->y);
                $this->assertTrue(
                    $dx >= $cfg['node_w'] || $dy >= $cfg['node_h'],
                    "nodes overlap: dx={$dx} dy={$dy} (need dx>={$cfg['node_w']} or dy>={$cfg['node_h']})",
                );
            }
        }
    }

    public function test_upsert_is_idempotent(): void
    {
        $this->doImport();
        $this->doImport();

        $this->assertSame(3, Device::count());
        $this->assertSame(1, Link::count());
        $this->assertSame(1, Credential::where('name', 'v2-public')->count());
        // History re-imports add rows (append-only) - config stays stable, which is the contract.
    }

    public function test_fresh_mode_wipes_existing_config_first(): void
    {
        $stale = Device::create([
            'name' => 'old-device', 'mgmt_ip' => '192.0.2.99', 'poll_method' => 'snmp', 'status' => 'up',
        ]);

        $this->doImport(ImportMode::Fresh);

        $this->assertNull(Device::find($stale->id));
        $this->assertSame(3, Device::count());
        $this->assertFalse(Device::where('mgmt_ip', '192.0.2.99')->exists());
    }

    public function test_upsert_refreshes_interface_speed_from_the_import(): void
    {
        // Interface speed is read-only: a re-import refreshes it from
        // the imported value (capacity overrides live on links, not interfaces).
        $this->doImport();
        $ether1 = NetworkInterface::where('if_index', 1)->firstOrFail();
        $imported = $ether1->speed_mbps;
        $ether1->update(['speed_mbps' => 300]);

        $this->doImport();

        $this->assertSame($imported, $ether1->fresh()->speed_mbps);
    }

    public function test_cancellation_rolls_back_config(): void
    {
        $run = ImportRun::create([
            'original_filename' => 'dude.db', 'stored_path' => 'imports/x/dude.db',
            'mode' => 'upsert', 'include_history' => true, 'status' => 'importing',
            'cancel_requested' => true,
        ]);

        $this->expectException(ImportCancelled::class);
        try {
            app(ImportDudeDatabase::class)($run, $this->fixtures);
        } finally {
            // The whole config import ran inside a transaction -> nothing persisted.
            $this->assertSame(0, Device::count());
            $this->assertSame(0, Credential::count());
        }
    }

    public function test_history_can_be_skipped(): void
    {
        $summary = $this->doImport(ImportMode::Upsert, history: false);

        $this->assertArrayNotHasKey('history', $summary);
        $this->assertSame(0, DB::table('interface_samples')->count());
        $this->assertSame(3, Device::count()); // config still imported
    }
}
