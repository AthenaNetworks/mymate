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

    public function test_test_endpoint_reads_an_oid_via_snmp(): void
    {
        // GitHub #40: validate an OID against a device without saving the sensor.
        $this->actingAsUser();
        $snmp = new \Tests\Support\FakeSnmpClient;
        $snmp->getsByCommunity = ['public' => ['1.3.6.1.4.1.9.9.13.1.3.1.3.1' => '2200']];
        $this->app->instance(\App\Services\Snmp\SnmpClient::class, $snmp);

        $cred = \App\Models\Credential::factory()->create(['snmp_community' => 'public']);
        $device = \App\Models\Device::factory()->create(['poll_method' => \App\Enums\PollMethod::Snmp, 'credential_id' => $cred->id]);

        $res = $this->postJson('/api/sensors/test', [
            'device_id' => $device->id, 'oid' => '1.3.6.1.4.1.9.9.13.1.3.1.3.1', 'mode' => 'get', 'divisor' => 100,
        ])->assertOk()->assertJsonPath('data.ok', true);

        $this->assertEqualsWithDelta(22.0, $res->json('data.value'), 0.001); // 2200 / 100
    }

    public function test_test_endpoint_reports_a_device_without_snmp(): void
    {
        $this->actingAsUser();
        $device = \App\Models\Device::factory()->create(); // no usable SNMP credential

        $this->postJson('/api/sensors/test', ['device_id' => $device->id, 'oid' => '1.3.6.1'])
            ->assertOk()->assertJsonPath('data.ok', false);
    }

    public function test_on_face_flag_persists(): void
    {
        $this->actingAsUser();
        $id = $this->postJson('/api/sensors', ['name' => 'Temp', 'oid' => '1.3.6.1.2.1.1.1', 'on_face' => true])
            ->assertCreated()->assertJsonPath('data.on_face', true)->json('data.id');

        $this->putJson("/api/sensors/{$id}", ['on_face' => false])->assertOk()->assertJsonPath('data.on_face', false);
    }

    public function test_face_sensors_endpoint_returns_only_on_face_readings_grouped_by_device(): void
    {
        $this->actingAsUser();
        $onFace = Sensor::factory()->create(['name' => 'Temp', 'unit' => '°C', 'enabled' => true, 'on_face' => true]);
        $offFace = Sensor::factory()->create(['name' => 'Hidden', 'enabled' => true, 'on_face' => false]);
        $device = \App\Models\Device::factory()->create();

        \Illuminate\Support\Facades\DB::table('sensor_readings')->insert([
            ['sensor_id' => $onFace->id, 'device_id' => $device->id, 'value' => 22.0, 'read_at' => now()],
            ['sensor_id' => $offFace->id, 'device_id' => $device->id, 'value' => 99.0, 'read_at' => now()],
        ]);

        $data = $this->getJson('/api/face-sensors')->assertOk()->json('data');
        $this->assertCount(1, $data[$device->id]);          // only the on_face reading
        $this->assertSame('Temp', $data[$device->id][0]['name']);
        $this->assertEqualsWithDelta(22.0, $data[$device->id][0]['value'], 0.001);
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
