<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Probe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProbeApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_list_update_and_delete_a_probe(): void
    {
        $this->actingAsUser();
        $device = Device::factory()->create();

        $id = $this->postJson("/api/devices/{$device->id}/probes", [
            'name' => 'Portal', 'kind' => 'http',
            'config' => ['url' => 'https://portal.test/health', 'expect_status' => '200'],
        ])->assertCreated()->assertJsonPath('data.name', 'Portal')->json('data.id');

        $this->getJson("/api/devices/{$device->id}/probes")->assertOk()->assertJsonCount(1, 'data');

        $this->putJson("/api/probes/{$id}", ['name' => 'Portal HTTPS', 'enabled' => false])
            ->assertOk()->assertJsonPath('data.name', 'Portal HTTPS')->assertJsonPath('data.enabled', false);

        $this->deleteJson("/api/probes/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('probes', ['id' => $id]);
    }

    public function test_validation_rejects_bad_config(): void
    {
        $this->actingAsUser();
        $device = Device::factory()->create();

        // http without a url
        $this->postJson("/api/devices/{$device->id}/probes", ['name' => 'x', 'kind' => 'http', 'config' => []])
            ->assertStatus(422)->assertJsonValidationErrors('config.url');

        // tcp without a port
        $this->postJson("/api/devices/{$device->id}/probes", ['name' => 'x', 'kind' => 'tcp', 'config' => []])
            ->assertStatus(422)->assertJsonValidationErrors('config.port');

        // bad port
        $this->postJson("/api/devices/{$device->id}/probes", ['name' => 'x', 'kind' => 'tcp', 'config' => ['port' => 70000]])
            ->assertStatus(422)->assertJsonValidationErrors('config.port');
    }

    public function test_test_endpoint_runs_the_probe_now(): void
    {
        $this->actingAsUser();
        Http::fake(['*' => Http::response('ok', 200)]);
        $probe = Probe::factory()->create(['config' => ['url' => 'https://x.test/', 'expect_status' => '200']]);

        $this->postJson("/api/probes/{$probe->id}/test")
            ->assertOk()
            ->assertJsonPath('data.up', true)
            ->assertJsonPath('data.message', 'HTTP 200');
    }

    public function test_non_admin_cannot_create_a_probe(): void
    {
        $viewer = User::factory()->create(['is_admin' => false]);
        $device = Device::factory()->create();

        $this->actingAs($viewer)
            ->postJson("/api/devices/{$device->id}/probes", ['name' => 'x', 'kind' => 'tcp', 'config' => ['port' => 443]])
            ->assertForbidden();
    }
}
