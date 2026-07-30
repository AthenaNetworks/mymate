<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsUser();
    }

    public function test_lists_sites_with_device_and_down_counts(): void
    {
        $site = Site::factory()->create();
        Device::factory()->count(3)->create(['site_id' => $site->id]);
        Device::factory()->create(['site_id' => $site->id, 'status' => 'down']);

        $this->getJson('/api/sites')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.device_count', 4)
            ->assertJsonPath('data.0.devices_down', 1);
    }

    public function test_creates_a_site(): void
    {
        $this->postJson('/api/sites', [
            'name' => 'North Tower', 'kind' => 'tower', 'latitude' => 40.0, 'longitude' => -100.0,
            'external_ref' => 'inv:abc-123',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'North Tower')
            ->assertJsonPath('data.kind', 'tower');

        $this->assertDatabaseHas('sites', ['external_ref' => 'inv:abc-123', 'name' => 'North Tower']);
    }

    public function test_rejects_a_half_set_coordinate(): void
    {
        $this->postJson('/api/sites', ['name' => 'Half', 'latitude' => 40.0])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['longitude']);
    }

    public function test_rejects_a_duplicate_external_ref(): void
    {
        Site::factory()->create(['external_ref' => 'inv:dup']);

        $this->postJson('/api/sites', ['name' => 'Other', 'external_ref' => 'inv:dup'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['external_ref']);
    }

    public function test_deleting_a_site_detaches_its_devices_rather_than_removing_them(): void
    {
        $site = Site::factory()->create();
        $device = Device::factory()->create(['site_id' => $site->id, 'site_source' => 'import']);

        $this->deleteJson("/api/sites/{$site->id}")->assertNoContent();

        $this->assertDatabaseMissing('sites', ['id' => $site->id]);
        $this->assertDatabaseHas('devices', ['id' => $device->id, 'site_id' => null]);
    }

    public function test_non_admin_cannot_create_a_site(): void
    {
        $this->actingAs(User::factory()->create()); // read-only tier

        $this->postJson('/api/sites', ['name' => 'Nope'])->assertForbidden();
    }
}
