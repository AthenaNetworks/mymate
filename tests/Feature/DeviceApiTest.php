<?php

namespace Tests\Feature;

use App\Enums\DeviceType;
use App\Models\Credential;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsUser();
    }

    public function test_it_lists_devices(): void
    {
        Device::factory()->count(3)->create();

        $this->getJson('/api/devices')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure(['data' => [['id', 'name', 'mgmt_ip', 'poll_method', 'status', 'map_x', 'map_y']]]);
    }

    public function test_it_creates_a_device_with_default_unknown_status(): void
    {
        $this->postJson('/api/devices', ['name' => 'Edge1', 'mgmt_ip' => '10.0.0.1', 'poll_method' => 'snmp'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Edge1')
            ->assertJsonPath('data.status', 'unknown');

        $this->assertDatabaseHas('devices', ['name' => 'Edge1', 'mgmt_ip' => '10.0.0.1', 'status' => 'unknown']);
    }

    public function test_it_validates_on_create(): void
    {
        $this->postJson('/api/devices', ['name' => '', 'mgmt_ip' => 'not-an-ip', 'poll_method' => 'bogus'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'mgmt_ip', 'poll_method']);
    }

    public function test_it_shows_a_device(): void
    {
        $device = Device::factory()->create();

        $this->getJson("/api/devices/{$device->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $device->id);
    }

    public function test_it_updates_a_device(): void
    {
        $device = Device::factory()->create(['name' => 'Old']);

        $this->putJson("/api/devices/{$device->id}", ['name' => 'New'])
            ->assertOk()
            ->assertJsonPath('data.name', 'New');

        $this->assertDatabaseHas('devices', ['id' => $device->id, 'name' => 'New']);
    }

    public function test_it_toggles_monitoring(): void
    {
        $device = Device::factory()->create(['monitored' => true]);

        $this->putJson("/api/devices/{$device->id}", ['monitored' => false])
            ->assertOk()
            ->assertJsonPath('data.monitored', false);

        $this->assertDatabaseHas('devices', ['id' => $device->id, 'monitored' => false]);
    }

    public function test_it_persists_position(): void
    {
        $device = Device::factory()->create(['map_x' => 0, 'map_y' => 0]);

        $this->patchJson("/api/devices/{$device->id}/position", ['map_x' => 120.5, 'map_y' => -33.25])
            ->assertOk()
            ->assertJsonPath('data.map_x', 120.5)
            ->assertJsonPath('data.map_y', -33.25);

        $this->assertDatabaseHas('devices', ['id' => $device->id, 'map_x' => 120.5, 'map_y' => -33.25]);
    }

    public function test_it_deletes_a_device(): void
    {
        $device = Device::factory()->create();

        $this->deleteJson("/api/devices/{$device->id}")->assertNoContent();

        $this->assertDatabaseMissing('devices', ['id' => $device->id]);
    }

    public function test_it_never_exposes_credential_secrets(): void
    {
        $credential = Credential::create([
            'name' => 'Shared SNMP',
            'type' => 'snmp',
            'snmp_community' => 'supersecret-community',
            'api_port' => 8728,
        ]);
        $device = Device::factory()->create(['credential_id' => $credential->id]);

        $json = $this->getJson("/api/devices/{$device->id}")->assertOk()->getContent();

        $this->assertStringNotContainsString('supersecret-community', $json);
        $this->assertStringNotContainsString('snmp_community', $json);
    }

    public function test_it_creates_a_device_with_type_and_parent(): void
    {
        $parent = Device::factory()->create();

        $this->postJson('/api/devices', [
            'name' => 'Edge2', 'mgmt_ip' => '10.0.0.2', 'poll_method' => 'routeros',
            'device_type' => 'switch', 'parent_device_id' => $parent->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.device_type', 'switch')
            ->assertJsonPath('data.parent_device_id', $parent->id)
            ->assertJsonPath('data.parent_name', $parent->name);

        $this->assertDatabaseHas('devices', [
            'name' => 'Edge2', 'device_type' => 'switch', 'parent_device_id' => $parent->id,
        ]);
    }

    public function test_resource_exposes_metadata_fields(): void
    {
        $device = Device::factory()->create([
            'device_type' => DeviceType::Router, 'vendor' => 'MikroTik', 'model' => 'CCR2004', 'uptime_seconds' => 3600,
        ]);

        $this->getJson("/api/devices/{$device->id}")
            ->assertOk()
            ->assertJsonPath('data.device_type', 'router')
            ->assertJsonPath('data.vendor', 'MikroTik')
            ->assertJsonPath('data.model', 'CCR2004')
            ->assertJsonPath('data.uptime_seconds', 3600);
    }

    public function test_it_defaults_device_type_to_unknown(): void
    {
        $this->postJson('/api/devices', ['name' => 'Plain', 'mgmt_ip' => '10.0.0.9', 'poll_method' => 'snmp'])
            ->assertCreated()
            ->assertJsonPath('data.device_type', 'unknown');
    }

    public function test_it_rejects_an_invalid_device_type(): void
    {
        $device = Device::factory()->create();

        $this->putJson("/api/devices/{$device->id}", ['device_type' => 'toaster'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['device_type']);
    }

    public function test_a_device_cannot_be_its_own_parent(): void
    {
        $device = Device::factory()->create();

        $this->putJson("/api/devices/{$device->id}", ['parent_device_id' => $device->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['parent_device_id']);
    }

    public function test_it_creates_a_ping_only_device(): void
    {
        //  (FR-36): `none` is a valid poll method - pinged for up/down, no throughput.
        $this->postJson('/api/devices', ['name' => 'Shed Camera', 'mgmt_ip' => '192.168.1.50', 'poll_method' => 'none'])
            ->assertCreated()
            ->assertJsonPath('data.poll_method', 'none');

        $this->assertDatabaseHas('devices', ['name' => 'Shed Camera', 'poll_method' => 'none']);
    }

    public function test_it_can_switch_an_existing_device_to_ping_only(): void
    {
        $device = Device::factory()->create(['poll_method' => 'snmp']);

        $this->putJson("/api/devices/{$device->id}", ['poll_method' => 'none'])
            ->assertOk()
            ->assertJsonPath('data.poll_method', 'none');

        $this->assertDatabaseHas('devices', ['id' => $device->id, 'poll_method' => 'none']);
    }

    public function test_it_rejects_a_loopback_management_ip(): void
    {
        // : reject obviously-wrong mgmt IPs (loopback = the monitor box itself)
        // at the door via the ManageableIp rule; a normal IP still passes.
        $this->postJson('/api/devices', ['name' => 'Bad', 'mgmt_ip' => '127.0.0.1', 'poll_method' => 'snmp'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mgmt_ip']);

        $this->postJson('/api/devices', ['name' => 'Multicast', 'mgmt_ip' => '224.0.0.1', 'poll_method' => 'snmp'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mgmt_ip']);

        $this->postJson('/api/devices', ['name' => 'Good', 'mgmt_ip' => '10.0.0.5', 'poll_method' => 'snmp'])
            ->assertCreated();
    }

    public function test_it_rejects_updating_to_a_loopback_management_ip(): void
    {
        $device = Device::factory()->create(['mgmt_ip' => '10.0.0.7']);

        $this->putJson("/api/devices/{$device->id}", ['mgmt_ip' => '127.0.0.1'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mgmt_ip']);
    }
}
