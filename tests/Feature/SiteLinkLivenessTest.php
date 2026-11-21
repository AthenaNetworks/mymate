<?php

namespace Tests\Feature;

use App\Enums\DeviceStatus;
use App\Models\Device;
use App\Models\NetworkInterface;
use App\Models\Site;
use App\Models\SiteLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Site-link binding + derived liveness (GitHub #85, phase 1): the geo backhaul feed carries the
 * binding and live numbers, the write validates that an end is at the right site, and a link
 * cannot read `up` on stale or missing data.
 */
class SiteLinkLivenessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsUser();
    }

    /**
     * A site with one router and one interface on it.
     *
     * @param  array<string, mixed>  $device
     * @param  array<string, mixed>  $interface
     * @return array{0: Site, 1: Device, 2: NetworkInterface}
     */
    private function end(float $lat, float $lng, array $device = [], array $interface = []): array
    {
        $site = Site::factory()->at($lat, $lng)->create();
        $router = Device::factory()->create(['site_id' => $site->id] + $device);
        $port = NetworkInterface::factory()->create(['device_id' => $router->id] + $interface);

        return [$site, $router, $port];
    }

    /** An interface that has just produced a healthy sample at a known speed. */
    private function freshPort(array $overrides = []): array
    {
        return array_merge([
            'oper_status' => 'up',
            'speed_mbps' => 1000,
            'bps_in' => 1_000_000,
            'bps_out' => 2_000_000,
            'util_in' => 0.1,
            'util_out' => 0.2,
            'last_ts' => now(),
        ], $overrides);
    }

    public function test_backhauls_report_binding_role_and_live_throughput(): void
    {
        [$aSite, , $aPort] = $this->end(40.0, -100.0, [], $this->freshPort());
        [$bSite, , $bPort] = $this->end(41.0, -101.0, [], $this->freshPort(['bps_in' => 9_000_000, 'bps_out' => 3_000_000]));

        $link = SiteLink::create([
            'site_a_id' => $aSite->id,
            'site_b_id' => $bSite->id,
            'media_type' => 'wireless',
            'a_interface_id' => $aPort->id,
            'b_interface_id' => $bPort->id,
            'role' => 'primary',
        ]);

        $row = $this->getJson('/api/geo/backhauls')->assertOk()->json('data.0');

        $this->assertSame($link->id, $row['id']);
        $this->assertSame('primary', $row['role']);
        $this->assertSame('up', $row['state']);
        // A->B is the busier of A's out and B's in; B->A the reverse.
        $this->assertSame(9_000_000, $row['bps_ab']);
        $this->assertSame(3_000_000, $row['bps_ba']);
        $this->assertEqualsWithDelta(0.9, $row['util_ab'], 0.01);
        $this->assertSame(1000, $row['capacity_ab_mbps']);
        $this->assertSame($aPort->id, $row['a_interface_id']);
    }

    public function test_a_link_with_no_bound_ends_still_renders_but_is_unknown(): void
    {
        $a = Site::factory()->at(40.0, -100.0)->create();
        $b = Site::factory()->at(41.0, -101.0)->create();
        SiteLink::create(['site_a_id' => $a->id, 'site_b_id' => $b->id, 'media_type' => 'fiber']);

        $row = $this->getJson('/api/geo/backhauls')->assertOk()->json('data.0');

        $this->assertSame('unknown', $row['role']);
        $this->assertSame('unknown', $row['state']);
        $this->assertSame('unbound', $row['reason']);
        $this->assertNull($row['util']);
    }

    public function test_patch_binds_an_end_and_records_the_verification(): void
    {
        [$aSite, , $aPort] = $this->end(40.0, -100.0);
        $bSite = Site::factory()->at(41.0, -101.0)->create();
        $link = SiteLink::create([
            'site_a_id' => $aSite->id,
            'site_b_id' => $bSite->id,
            'media_type' => 'fiber',
        ]);

        $row = $this->patchJson("/api/site-links/{$link->id}", [
            'a_interface_id' => $aPort->id,
            'role' => 'backup',
            'capacity_ab_mbps' => 10_000,
        ])->assertOk()->json('data');

        $this->assertSame($aPort->id, $row['a_interface_id']);
        $this->assertSame('backup', $row['role']);
        $this->assertSame('manual', $row['binding_source']);
        $this->assertSame(10_000, $row['capacity_ab_mbps']);
        $this->assertNotNull($row['verified_at']);

        $fresh = $link->fresh();
        $this->assertSame($aPort->id, $fresh->a_interface_id);
        $this->assertNotNull($fresh->verified_at);
    }

    public function test_an_interface_at_another_site_is_rejected(): void
    {
        [$aSite] = $this->end(40.0, -100.0);
        $bSite = Site::factory()->at(41.0, -101.0)->create();
        // The port belongs to a device at *b*, not at this link's a-end.
        [, , $wrongPort] = $this->end(42.0, -102.0);

        $link = SiteLink::create(['site_a_id' => $aSite->id, 'site_b_id' => $bSite->id]);

        $this->patchJson("/api/site-links/{$link->id}", ['a_interface_id' => $wrongPort->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('a_interface_id');

        $this->assertNull($link->fresh()->a_interface_id);
    }

    public function test_one_interface_cannot_be_bound_to_two_site_links(): void
    {
        [$aSite, , $aPort] = $this->end(40.0, -100.0);
        $bSite = Site::factory()->at(41.0, -101.0)->create();
        $cSite = Site::factory()->at(42.0, -102.0)->create();

        $first = SiteLink::create(['site_a_id' => $aSite->id, 'site_b_id' => $bSite->id, 'a_interface_id' => $aPort->id]);
        $second = SiteLink::create(['site_a_id' => $aSite->id, 'site_b_id' => $cSite->id]);

        $this->patchJson("/api/site-links/{$second->id}", ['a_interface_id' => $aPort->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('a_interface_id');

        $this->assertSame($aPort->id, $first->fresh()->a_interface_id);
    }

    public function test_a_down_router_reads_down_even_with_fresh_counters(): void
    {
        [$aSite, , $aPort] = $this->end(40.0, -100.0);
        [$bSite, , $bPort] = $this->end(41.0, -101.0, ['status' => DeviceStatus::Down], $this->freshPort());
        $link = SiteLink::create([
            'site_a_id' => $aSite->id, 'site_b_id' => $bSite->id,
            'a_interface_id' => $aPort->id, 'b_interface_id' => $bPort->id,
        ]);

        $row = $this->getJson('/api/geo/backhauls')->assertOk()->json('data.0');

        $this->assertSame('down', $row['state']);
        $this->assertSame('device_down', $row['reason']);
    }

    public function test_a_stale_link_is_unknown_rather_than_up(): void
    {
        [$aSite, , $aPort] = $this->end(40.0, -100.0, [], $this->freshPort(['last_ts' => now()->subMinutes(10)]));
        [$bSite, , $bPort] = $this->end(41.0, -101.0, [], $this->freshPort(['last_ts' => now()->subMinutes(10)]));
        SiteLink::create([
            'site_a_id' => $aSite->id, 'site_b_id' => $bSite->id,
            'a_interface_id' => $aPort->id, 'b_interface_id' => $bPort->id,
        ]);

        $row = $this->getJson('/api/geo/backhauls')->assertOk()->json('data.0');

        $this->assertSame('unknown', $row['state']);
        $this->assertSame('no_data', $row['reason']);
    }

    public function test_a_saturated_link_reads_degraded(): void
    {
        [$aSite, , $aPort] = $this->end(40.0, -100.0, [], $this->freshPort(['bps_out' => 900_000_000]));
        [$bSite, , $bPort] = $this->end(41.0, -101.0, [], $this->freshPort(['bps_in' => 900_000_000]));
        SiteLink::create([
            'site_a_id' => $aSite->id, 'site_b_id' => $bSite->id,
            'a_interface_id' => $aPort->id, 'b_interface_id' => $bPort->id,
        ]);

        $row = $this->getJson('/api/geo/backhauls')->assertOk()->json('data.0');

        $this->assertSame('degraded', $row['state']);
        $this->assertSame('saturated', $row['reason']);
    }

    public function test_throughput_composites_both_directions_from_the_bound_ends(): void
    {
        [$aSite, , $aPort] = $this->end(40.0, -100.0);
        [$bSite, , $bPort] = $this->end(41.0, -101.0);
        $link = SiteLink::create([
            'site_a_id' => $aSite->id, 'site_b_id' => $bSite->id,
            'a_interface_id' => $aPort->id, 'b_interface_id' => $bPort->id,
        ]);

        $ts = now()->subMinutes(2)->startOfMinute();
        foreach ([[$aPort->id, 1_000_000, 5_000_000], [$bPort->id, 5_000_000, 1_000_000]] as [$id, $in, $out]) {
            DB::table('interface_samples')->insert([
                'interface_id' => $id,
                'ts' => $ts->format('Y-m-d H:i:s'),
                'bps_in' => $in,
                'bps_out' => $out,
                'util_in' => null,
                'util_out' => null,
            ]);
        }

        $response = $this->getJson(
            "/api/site-links/{$link->id}/throughput?from=".now()->subMinutes(10)->format('Y-m-d\TH:i:s')
            .'&to='.now()->format('Y-m-d\TH:i:s')
        )->assertOk();

        // Buckets are floored to the window grid, so match on the values rather than the raw ts.
        $row = collect($response->json('data'))->first(fn (array $r) => $r['bps_ab'] === 5_000_000);

        $this->assertNotNull($row);
        $this->assertSame(5_000_000, $row['bps_ab']);
        $this->assertSame(1_000_000, $row['bps_ba']);
        $this->assertEqualsWithDelta(0.5, $row['util_ab'], 0.001);
        $this->assertSame($aPort->id, $response->json('meta.a_interface_id'));
    }
}
