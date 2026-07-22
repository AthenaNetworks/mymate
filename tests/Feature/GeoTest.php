<?php

namespace Tests\Feature;

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * GitHub #11: the geo overlay's tile config, the geocoder proxy, and the CSP allowing tiles.
 */
class GeoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsUser();
    }

    public function test_map_config_reports_the_tile_settings(): void
    {
        config(['mymate.map.tile_url' => 'https://tile.example/{z}/{x}/{y}.png', 'mymate.map.geocoder_url' => 'https://geo.example']);

        $this->getJson('/api/map-config')
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.tile_url', 'https://tile.example/{z}/{x}/{y}.png')
            ->assertJsonPath('data.geocoder_enabled', true);
    }

    public function test_map_config_disabled_when_no_tile_url(): void
    {
        config(['mymate.map.tile_url' => '']);
        $this->getJson('/api/map-config')->assertOk()->assertJsonPath('data.enabled', false);
    }

    public function test_geocode_proxies_the_provider_and_returns_coordinates(): void
    {
        config(['mymate.map.geocoder_url' => 'https://geo.example/search']);
        Http::fake(['geo.example/*' => Http::response([['lat' => '-27.4698', 'lon' => '153.0251', 'display_name' => 'Brisbane']])]);

        $this->getJson('/api/geocode?q=Brisbane')
            ->assertOk()
            ->assertJsonPath('data.lat', -27.4698)
            ->assertJsonPath('data.lng', 153.0251)
            ->assertJsonPath('data.label', 'Brisbane');
    }

    public function test_geocode_returns_null_on_no_match(): void
    {
        config(['mymate.map.geocoder_url' => 'https://geo.example/search']);
        Http::fake(['geo.example/*' => Http::response([])]);

        $this->getJson('/api/geocode?q=nowhere')->assertOk()->assertExactJson(['data' => null]);
    }

    public function test_setting_coordinates_marks_the_source_manual(): void
    {
        $device = Device::factory()->create();

        $this->patchJson("/api/devices/{$device->id}", ['latitude' => -27.47, 'longitude' => 153.02])
            ->assertOk()
            ->assertJsonPath('data.geo_source', 'manual');

        $this->assertDatabaseHas('devices', ['id' => $device->id, 'geo_source' => 'manual']);
    }

    public function test_csp_allows_the_configured_tile_host(): void
    {
        config(['mymate.map.tile_csp_hosts' => 'https://tiles.example.net']);
        // The security headers ride the web (SPA) response.
        $csp = $this->get('/')->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("img-src 'self' data: https://tiles.example.net", (string) $csp);
    }
}
