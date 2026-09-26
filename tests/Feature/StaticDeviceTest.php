<?php

namespace Tests\Feature;

use App\Actions\Agent\DispatchAgentJobs;
use App\Enums\PollMethod;
use App\Models\Agent;
use App\Models\Device;
use App\Models\DeviceMapPosition;
use App\Models\Link;
use App\Models\Map;
use App\Models\NetworkInterface;
use App\Services\Polling\PingDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Static map objects (GitHub #9 / #28 / #49): a device with no management IP - a dumb switch, a
 * patch panel - that you draw and link to like The Dude's static elements, but that is never polled.
 */
class StaticDeviceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_ping_only_device_can_be_added_with_no_ip(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/devices', ['name' => 'Dumb switch', 'mgmt_ip' => null, 'poll_method' => 'none', 'device_type' => 'switch'])
            ->assertCreated()->assertJsonPath('data.mgmt_ip', null);

        // Any number of them - NULL IPs never collide in the per-scope unique index.
        $this->postJson('/api/devices', ['name' => 'Patch panel', 'poll_method' => 'none'])->assertCreated();
        $this->assertSame(2, Device::whereNull('mgmt_ip')->count());
    }

    public function test_a_polled_device_still_needs_an_ip(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/devices', ['name' => 'Router', 'poll_method' => 'snmp'])
            ->assertStatus(422)->assertJsonValidationErrors('mgmt_ip');

        // Nor can an existing SNMP device drop its IP - but a ping-only one can become static.
        $snmp = Device::factory()->create(['poll_method' => PollMethod::Snmp]);
        $this->putJson("/api/devices/{$snmp->id}", ['mgmt_ip' => null])->assertStatus(422)->assertJsonValidationErrors('mgmt_ip');

        $ping = Device::factory()->create(['poll_method' => PollMethod::None]);
        $this->putJson("/api/devices/{$ping->id}", ['mgmt_ip' => null])->assertOk()->assertJsonPath('data.mgmt_ip', null);
    }

    public function test_a_static_object_is_never_handed_to_a_poller(): void
    {
        Queue::fake();
        Device::factory()->create(['mgmt_ip' => null, 'poll_method' => PollMethod::None]);

        $this->assertSame(0, Device::pollable()->count());
        // Only a static object on the install -> no ping sweep dispatched at all.
        $this->assertSame(0, app(PingDispatcher::class)->dispatch());

        // And an agent never gets it as a ping target either.
        $agent = Agent::factory()->create();
        Device::factory()->create(['mgmt_ip' => null, 'poll_method' => PollMethod::None, 'agent_id' => $agent->id]);
        $real = Device::factory()->create(['mgmt_ip' => '10.1.1.1', 'agent_id' => $agent->id]);
        $job = app(DispatchAgentJobs::class)->buildJob($agent->id);
        $this->assertSame([$real->id], array_column($job['poll']['ping'], 'device_id'));
    }

    public function test_a_real_device_can_link_to_a_static_object(): void
    {
        $this->actingAsUser();
        $router = Device::factory()->create();
        $ether1 = NetworkInterface::factory()->for($router)->create(['speed_mbps' => 1000]);
        $switch = Device::factory()->create(['mgmt_ip' => null, 'poll_method' => PollMethod::None]);

        // The static end has no interfaces - the link's traffic and status come from the router's port.
        $this->postJson('/api/links', [
            'a_device_id' => $router->id, 'a_interface_id' => $ether1->id,
            'b_device_id' => $switch->id, 'b_interface_id' => null,
        ])->assertCreated()->assertJsonPath('data.b_interface_id', null);
    }

    public function test_backups_cannot_be_enabled_on_a_static_object(): void
    {
        $this->actingAsUser();
        $switch = Device::factory()->create(['mgmt_ip' => null, 'poll_method' => PollMethod::None]);

        $this->putJson("/api/devices/{$switch->id}/backup-config", ['backup_enabled' => true, 'backup_driver' => 'mikrotik_routeros'])
            ->assertStatus(422)->assertJsonValidationErrors('backup_enabled');
        $this->assertFalse((bool) $switch->fresh()->backup_enabled);
    }

    public function test_map_export_and_import_keep_a_static_object_and_its_link(): void
    {
        $this->actingAsUser();
        $map = Map::create(['name' => 'Site 9']);
        $router = Device::factory()->create(['name' => 'RTR', 'mgmt_ip' => '10.9.0.1']);
        $ether1 = NetworkInterface::factory()->for($router)->create(['name' => 'ether1']);
        $switch = Device::factory()->create(['name' => 'Dumb switch', 'mgmt_ip' => null, 'poll_method' => PollMethod::None]);
        DeviceMapPosition::create(['map_id' => $map->id, 'device_id' => $router->id, 'x' => 0, 'y' => 0]);
        DeviceMapPosition::create(['map_id' => $map->id, 'device_id' => $switch->id, 'x' => 100, 'y' => 0]);
        Link::create(['a_device_id' => $router->id, 'a_interface_id' => $ether1->id, 'b_device_id' => $switch->id, 'b_interface_id' => null]);

        $export = $this->getJson("/api/maps/{$map->id}/export")->assertOk()->json();
        $newMap = $this->postJson('/api/maps/import', $export)->assertCreated()->json('data.id');

        // The one existing static object with that name is reused, not duplicated, and the link is kept.
        $this->assertSame(1, Device::whereNull('mgmt_ip')->where('name', 'Dumb switch')->count());
        $this->assertDatabaseHas('device_map_positions', ['map_id' => $newMap, 'device_id' => $switch->id]);
        $this->assertSame(1, Link::where('b_device_id', $switch->id)->count());
    }
}
