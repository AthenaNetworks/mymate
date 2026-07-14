<?php

namespace Tests\Feature;

use App\Models\MaintenanceWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaintenanceWindowApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/maintenance-windows')->assertUnauthorized();
    }

    public function test_crud_lifecycle(): void
    {
        $this->actingAsUser();

        $res = $this->postJson('/api/maintenance-windows', [
            'name' => 'Core upgrade',
            'starts_at' => now()->addHour()->toIso8601String(),
            'ends_at' => now()->addHours(3)->toIso8601String(),
            'scope' => ['type' => 'all'],
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Core upgrade')
            ->assertJsonPath('data.active', false); // starts in the future

        $id = $res->json('data.id');

        $this->putJson("/api/maintenance-windows/{$id}", ['enabled' => false])
            ->assertOk()->assertJsonPath('data.enabled', false);

        $this->deleteJson("/api/maintenance-windows/{$id}")->assertNoContent();
        $this->assertDatabaseCount('maintenance_windows', 0);
    }

    public function test_rejects_an_end_before_the_start(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/maintenance-windows', [
            'name' => 'Bad',
            'starts_at' => now()->addHours(3)->toIso8601String(),
            'ends_at' => now()->addHour()->toIso8601String(),
        ])->assertStatus(422)->assertJsonValidationErrors('ends_at');
    }

    public function test_index_reports_the_active_flag(): void
    {
        $this->actingAsUser();
        MaintenanceWindow::factory()->create(['name' => 'Now']);        // active
        MaintenanceWindow::factory()->past()->create(['name' => 'Old']); // ended

        $data = collect($this->getJson('/api/maintenance-windows')->assertOk()->json('data'));

        $this->assertTrue($data->firstWhere('name', 'Now')['active']);
        $this->assertFalse($data->firstWhere('name', 'Old')['active']);
    }
}
