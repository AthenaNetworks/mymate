<?php

namespace Tests\Feature;

use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_effective_tunables_defaulting_to_config(): void
    {
        $this->actingAsUser();

        $res = $this->getJson('/api/settings')->assertOk();

        $ping = collect($res->json('data'))->firstWhere('key', 'ping.interval');
        $this->assertNotNull($ping);
        $this->assertSame((int) config('mymate.ping.interval'), $ping['value']); // config default, no override
    }

    public function test_update_persists_overrides_read_back_by_the_repository(): void
    {
        $this->actingAsUser();

        $this->putJson('/api/settings', ['settings' => [['key' => 'ping.interval', 'value' => 9]]])->assertOk();

        $this->assertDatabaseHas('settings', ['key' => 'ping.interval']);
        $this->assertSame(9, app(Settings::class)->getInt('ping.interval', 5));
    }

    public function test_update_rejects_out_of_range_and_unknown_keys(): void
    {
        $this->actingAsUser();

        $this->putJson('/api/settings', ['settings' => [['key' => 'ping.interval', 'value' => 99999]]])
            ->assertStatus(422);
        $this->putJson('/api/settings', ['settings' => [['key' => 'bogus.key', 'value' => 1]]])
            ->assertStatus(422);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/settings')->assertUnauthorized();
    }
}
