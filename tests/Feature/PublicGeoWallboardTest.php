<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceMapPosition;
use App\Models\Map;
use App\Models\MapShare;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Geo view on the public wallboard (GitHub #37). What matters: a share only shows the geo map when
 * it was made to, a logical-only link hands out exactly what it did before (no coordinates at all),
 * a geo link hands out only the effective position of its own map's devices, and a dead token
 * still gets nothing.
 */
class PublicGeoWallboardTest extends TestCase
{
    use RefreshDatabase;

    /** The device keys the logical wallboard has always sent - a logical share must not grow. */
    private const LOGICAL_KEYS = [
        'id', 'name', 'mgmt_ip', 'status', 'map_x', 'map_y', 'device_type', 'icon', 'icon_color', 'vendor', 'model',
        'cpu_pct', 'mem_used_pct', 'temp_c', 'rtt_ms', 'loss_pct', 'latency_good_ms', 'latency_bad_ms',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['mymate.map.tile_url' => 'https://tile.example/{z}/{x}/{y}.png', 'mymate.map.tile_attribution' => 'OSM']);
    }

    /** @return array{0: Map, 1: Device} */
    private function placedMap(): array
    {
        $map = Map::factory()->create(['name' => 'Towers']);
        $device = Device::factory()->create([
            'name' => 'tower-ap', 'mgmt_ip' => '10.8.8.8',
            'latitude' => -27.4698, 'longitude' => 153.0251, 'geo_source' => 'manual',
            'snmp_latitude' => -26.1111, 'snmp_longitude' => 152.2222,
        ]);
        DeviceMapPosition::create(['device_id' => $device->id, 'map_id' => $map->id, 'x' => 1, 'y' => 2]);

        return [$map, $device];
    }

    private function share(Map $map, ?string $view = null, bool $enabled = true): MapShare
    {
        $attrs = ['map_id' => $map->id, 'token' => MapShare::newToken(), 'enabled' => $enabled];
        if ($view !== null) {
            $attrs['view'] = $view;
        }

        return MapShare::create($attrs);
    }

    public function test_existing_shares_stay_logical_and_hand_out_no_coordinates(): void
    {
        [$map] = $this->placedMap();
        $share = $this->share($map); // made the way every pre-#37 share was

        $this->assertSame('logical', $share->fresh()->view);

        $this->getJson("/api/public/wall/{$share->token}/map")->assertOk()->assertJsonPath('share.view', 'logical');

        $res = $this->getJson("/api/public/wall/{$share->token}/devices")->assertOk();
        $this->assertEqualsCanonicalizing(self::LOGICAL_KEYS, array_keys($res->json('data.0')));
        foreach (['-27.4698', '153.0251', '-26.1111', '152.2222'] as $coord) {
            $this->assertStringNotContainsString($coord, $res->getContent());
        }

        // No basemap for a link that wasn't made for the geo view.
        $this->getJson("/api/public/wall/{$share->token}/map-config")->assertNotFound();
    }

    public function test_a_geo_share_gets_only_the_effective_position_and_nothing_secret(): void
    {
        [$map] = $this->placedMap();
        $share = $this->share($map, 'geo');

        $this->getJson("/api/public/wall/{$share->token}/map")->assertOk()->assertJsonPath('share.view', 'geo');

        $res = $this->getJson("/api/public/wall/{$share->token}/devices")->assertOk();
        $body = $res->json('data.0');
        $this->assertEqualsCanonicalizing([...self::LOGICAL_KEYS, 'geo_latitude', 'geo_longitude'], array_keys($body));
        $this->assertEqualsWithDelta(-27.4698, $body['geo_latitude'], 0.0001);
        $this->assertEqualsWithDelta(153.0251, $body['geo_longitude'], 0.0001);
        $this->assertNull($body['mgmt_ip']);

        $raw = $res->getContent();
        $this->assertStringNotContainsString('10.8.8.8', $raw);
        // The SNMP-advertised coords (not where it's drawn) stay private.
        $this->assertStringNotContainsString('-26.1111', $raw);

        $this->getJson("/api/public/wall/{$share->token}/map-config")
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.tile_url', 'https://tile.example/{z}/{x}/{y}.png')
            ->assertJsonPath('data.geocoder_enabled', false);
    }

    public function test_a_geo_share_never_shows_another_maps_devices(): void
    {
        [$map] = $this->placedMap();
        $other = Map::factory()->create();
        $secret = Device::factory()->create(['name' => 'other-core', 'latitude' => -33.8688, 'longitude' => 151.2093]);
        DeviceMapPosition::create(['device_id' => $secret->id, 'map_id' => $other->id, 'x' => 0, 'y' => 0]);
        $share = $this->share($map, 'both');

        $res = $this->getJson("/api/public/wall/{$share->token}/devices")->assertOk()->assertJsonCount(1, 'data');
        $raw = $res->getContent();
        $this->assertStringNotContainsString('other-core', $raw);
        $this->assertStringNotContainsString('-33.8688', $raw);

        // Nor the photo of one.
        $this->get("/api/public/wall/{$share->token}/devices/{$secret->id}/icon")->assertNotFound();
    }

    public function test_position_inherited_through_an_off_map_parent_exposes_only_the_result(): void
    {
        $map = Map::factory()->create();
        $site = Site::factory()->at(-31.95, 115.86)->create(['name' => 'Secret Hill']);
        $tower = Device::factory()->create(['name' => 'hidden-tower', 'site_id' => $site->id]); // on no map
        $cpe = Device::factory()->create(['name' => 'cpe-1', 'parent_device_id' => $tower->id]);
        DeviceMapPosition::create(['device_id' => $cpe->id, 'map_id' => $map->id, 'x' => 0, 'y' => 0]);
        $share = $this->share($map, 'geo');

        $res = $this->getJson("/api/public/wall/{$share->token}/devices")->assertOk()->assertJsonCount(1, 'data');

        // Drawn where the authenticated geo map draws it...
        $this->assertEqualsWithDelta(-31.95, $res->json('data.0.geo_latitude'), 0.0001);
        $this->assertEqualsWithDelta(115.86, $res->json('data.0.geo_longitude'), 0.0001);
        // ...without naming the parent or the site it came from.
        $this->assertStringNotContainsString('hidden-tower', $res->getContent());
        $this->assertStringNotContainsString('Secret Hill', $res->getContent());
    }

    public function test_disabled_revoked_and_unknown_tokens_get_nothing_geo(): void
    {
        [$map] = $this->placedMap();
        $disabled = $this->share($map, 'geo', enabled: false);
        $revoked = $this->share($map, 'geo');
        $revoked->delete();

        foreach ([$disabled->token, $revoked->token, 'deadbeefdeadbeef'] as $token) {
            $this->getJson("/api/public/wall/{$token}/map-config")->assertNotFound();
            $this->getJson("/api/public/wall/{$token}/devices")->assertNotFound();
            $this->getJson("/api/public/wall/{$token}/map")->assertNotFound();
            $this->get("/wall/{$token}")->assertNotFound();
        }
    }

    public function test_admin_picks_the_view_when_minting_and_can_change_it(): void
    {
        $this->actingAsUser();
        [$map] = $this->placedMap();

        $id = $this->postJson("/api/maps/{$map->id}/shares", ['view' => 'geo'])
            ->assertCreated()
            ->assertJsonPath('data.view', 'geo')
            ->json('data.id');

        $this->patchJson("/api/maps/{$map->id}/shares/{$id}", ['view' => 'both'])->assertOk()->assertJsonPath('data.view', 'both');
        $this->patchJson("/api/maps/{$map->id}/shares/{$id}", ['view' => 'everything'])->assertUnprocessable();
        $this->postJson("/api/maps/{$map->id}/shares", ['view' => 'raw'])->assertUnprocessable();

        // Not saying keeps the old behaviour.
        $this->postJson("/api/maps/{$map->id}/shares")->assertCreated()->assertJsonPath('data.view', 'logical');
    }

    public function test_a_viewer_cannot_turn_a_share_geo(): void
    {
        [$map] = $this->placedMap();
        $share = $this->share($map);
        $viewer = User::factory()->create(['is_admin' => false]);

        $this->actingAs($viewer)->patchJson("/api/maps/{$map->id}/shares/{$share->id}", ['view' => 'geo'])->assertForbidden();
        $this->assertSame('logical', $share->fresh()->view);
    }

    public function test_the_wall_page_serves_a_geo_share(): void
    {
        [$map] = $this->placedMap();
        $share = $this->share($map, 'geo');

        $this->get("/wall/{$share->token}")->assertOk();
    }
}
