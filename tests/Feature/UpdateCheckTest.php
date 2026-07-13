<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UpdateCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsUser();
        Cache::flush();
        config(['mymate.update.repo' => 'AthenaNetworks/mymate']);
    }

    public function test_reports_up_to_date_when_versions_match(): void
    {
        config(['mymate.update.version' => '1.0.0']);
        Http::fake(['api.github.com/*' => Http::response(['tag_name' => 'v1.0.0'], 200)]);

        $this->getJson('/api/update-check')
            ->assertOk()
            ->assertJsonPath('data.current', '1.0.0')
            ->assertJsonPath('data.latest', 'v1.0.0')
            ->assertJsonPath('data.update_available', false);
    }

    public function test_flags_an_available_update(): void
    {
        config(['mymate.update.version' => '0.9.0']);
        Http::fake(['api.github.com/*' => Http::response(['tag_name' => 'v1.2.0'], 200)]);

        $this->getJson('/api/update-check?fresh=1')
            ->assertOk()
            ->assertJsonPath('data.update_available', true)
            ->assertJsonPath('data.latest', 'v1.2.0')
            ->assertJsonPath('data.url', 'https://github.com/AthenaNetworks/mymate/releases/latest');
    }

    public function test_is_graceful_when_github_is_unreachable(): void
    {
        config(['mymate.update.version' => '1.0.0']);
        Http::fake(['api.github.com/*' => Http::response('boom', 500)]);

        $this->getJson('/api/update-check')
            ->assertOk()
            ->assertJsonPath('data.latest', null)
            ->assertJsonPath('data.update_available', false);
    }
}
