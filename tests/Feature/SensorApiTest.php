<?php

namespace Tests\Feature;

use App\Models\Sensor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SensorApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/sensors')->assertUnauthorized();
    }

    public function test_crud_lifecycle(): void
    {
        $this->actingAsUser();

        $res = $this->postJson('/api/sensors', [
            'name' => 'WAN in errors', 'oid' => '1.3.6.1.2.1.2.2.1.14.1', 'unit' => 'errs', 'divisor' => 1,
        ])->assertCreated()->assertJsonPath('data.name', 'WAN in errors');

        $id = $res->json('data.id');

        $this->putJson("/api/sensors/{$id}", ['enabled' => false])->assertOk()->assertJsonPath('data.enabled', false);
        $this->deleteJson("/api/sensors/{$id}")->assertNoContent();
        $this->assertDatabaseCount('sensors', 0);
    }

    public function test_rejects_a_non_numeric_oid(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/sensors', ['name' => 'Bad', 'oid' => 'SNMPv2-MIB::sysUpTime.0'])
            ->assertStatus(422)->assertJsonValidationErrors('oid');
    }

    public function test_device_readings_endpoint_returns_current_values(): void
    {
        $this->actingAsUser();
        $sensor = Sensor::factory()->create(['name' => 'PoE draw', 'unit' => 'W']);
        $device = \App\Models\Device::factory()->create();
        \Illuminate\Support\Facades\DB::table('sensor_readings')->insert([
            'sensor_id' => $sensor->id, 'device_id' => $device->id, 'value' => 12.5, 'read_at' => now(),
        ]);

        $this->getJson("/api/devices/{$device->id}/sensors")
            ->assertOk()
            ->assertJsonPath('data.0.name', 'PoE draw')
            ->assertJsonPath('data.0.value', 12.5);
    }
}
