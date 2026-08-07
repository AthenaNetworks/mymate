<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceMapPosition;
use App\Models\Link;
use App\Models\Map;
use App\Models\NetworkInterface;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Per-map access control (GitHub #28). The security-critical properties: a restricted operator only
 * ever sees the maps/devices/links they're granted, route-model binding 404s the rest (covering
 * sub-resources), and unrestricted users, admins and the background engine are unaffected.
 */
class MapPermissionsTest extends TestCase
{
    use RefreshDatabase;

    /** Two maps, each with one device. Returns [mapA, devA, mapB, devB]. */
    private function twoMaps(): array
    {
        $mapA = Map::factory()->create(['name' => 'Region A']);
        $mapB = Map::factory()->create(['name' => 'Region B']);
        $devA = Device::factory()->create(['name' => 'a-core']);
        $devB = Device::factory()->create(['name' => 'b-core']);
        DeviceMapPosition::create(['device_id' => $devA->id, 'map_id' => $mapA->id, 'x' => 0, 'y' => 0]);
        DeviceMapPosition::create(['device_id' => $devB->id, 'map_id' => $mapB->id, 'x' => 0, 'y' => 0]);

        return [$mapA, $devA, $mapB, $devB];
    }

    private function restrictedUserFor(Map $map): User
    {
        $user = User::factory()->create(['is_admin' => false, 'restricted' => true]);
        $user->maps()->attach($map->id);

        return $user;
    }

    public function test_restricted_user_sees_only_granted_maps_and_devices(): void
    {
        [$mapA, $devA, $mapB, $devB] = $this->twoMaps();
        $this->actingAs($this->restrictedUserFor($mapA));

        $maps = $this->getJson('/api/maps')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([$mapA->id], collect($maps)->pluck('id')->all());

        $devices = $this->getJson('/api/devices')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([$devA->id], collect($devices)->pluck('id')->all());
    }

    public function test_out_of_scope_map_and_device_404_via_route_binding(): void
    {
        [$mapA, $devA, $mapB, $devB] = $this->twoMaps();
        $this->actingAs($this->restrictedUserFor($mapA));

        $this->getJson("/api/maps/{$mapB->id}")->assertNotFound();
        $this->getJson("/api/maps/{$mapA->id}")->assertOk();

        // Sub-resources are protected for free because {device} binding 404s out of scope.
        NetworkInterface::factory()->create(['device_id' => $devB->id]);
        $this->getJson("/api/devices/{$devB->id}/interfaces")->assertNotFound();
        $this->getJson("/api/devices/{$devB->id}/probes")->assertNotFound();
    }

    public function test_links_hidden_unless_both_ends_are_visible(): void
    {
        [$mapA, $devA, $mapB, $devB] = $this->twoMaps();
        // A third device also on map A, linked to devA (both visible) and a link A<->B (one hidden).
        $devA2 = Device::factory()->create();
        DeviceMapPosition::create(['device_id' => $devA2->id, 'map_id' => $mapA->id, 'x' => 1, 'y' => 1]);
        $visible = Link::create(['a_device_id' => $devA->id, 'b_device_id' => $devA2->id]);
        $crossing = Link::create(['a_device_id' => $devA->id, 'b_device_id' => $devB->id]);

        $this->actingAs($this->restrictedUserFor($mapA));

        $ids = collect($this->getJson('/api/links')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertContains($visible->id, $ids);
        $this->assertNotContains($crossing->id, $ids);
    }

    public function test_granting_a_parent_map_includes_its_sub_maps(): void
    {
        [$mapA, $devA, $mapB, $devB] = $this->twoMaps();
        // mapB becomes a child of mapA; granting A should now expose B's devices too.
        $mapB->update(['parent_map_id' => $mapA->id]);

        $this->actingAs($this->restrictedUserFor($mapA));

        $deviceIds = collect($this->getJson('/api/devices')->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$devA->id, $devB->id], $deviceIds);
    }

    public function test_unrestricted_viewer_and_admin_see_everything(): void
    {
        [$mapA, $devA, $mapB, $devB] = $this->twoMaps();

        $viewer = User::factory()->create(['is_admin' => false, 'restricted' => false]);
        $this->actingAs($viewer)->getJson('/api/devices')->assertOk()->assertJsonCount(2, 'data');

        $this->actingAsUser(); // admin
        $this->getJson('/api/devices')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_background_queries_without_an_auth_user_are_never_scoped(): void
    {
        [$mapA, $devA, $mapB, $devB] = $this->twoMaps();
        // Even with a restricted user in the DB, an unauthenticated (engine) context sees all -
        // both devices and both maps are reachable, not scoped to the user's single grant.
        $this->restrictedUserFor($mapA);

        $deviceIds = Device::pluck('id')->all();
        $this->assertContains($devA->id, $deviceIds);
        $this->assertContains($devB->id, $deviceIds);
        $mapIds = Map::pluck('id')->all();
        $this->assertContains($mapA->id, $mapIds);
        $this->assertContains($mapB->id, $mapIds);
    }

    public function test_admin_can_grant_maps_and_the_flag_round_trips(): void
    {
        $this->actingAsUser();
        [$mapA, $devA, $mapB, $devB] = $this->twoMaps();
        $viewer = User::factory()->create(['is_admin' => false]);

        $this->putJson("/api/users/{$viewer->id}", ['restricted' => true, 'map_ids' => [$mapA->id]])
            ->assertOk()
            ->assertJsonPath('restricted', true)
            ->assertJsonPath('map_ids', [$mapA->id]);

        $this->assertDatabaseHas('map_user', ['user_id' => $viewer->id, 'map_id' => $mapA->id]);
    }

    public function test_restricted_user_is_confined_to_map_viewing_endpoints(): void
    {
        [$mapA, $devA] = $this->twoMaps();
        $this->actingAs($this->restrictedUserFor($mapA));

        // Allowed: the map-viewing surface.
        $this->getJson('/api/maps')->assertOk();
        $this->getJson('/api/devices')->assertOk();
        $this->getJson('/api/links')->assertOk();

        // Denied: fleet-wide operator tools that would leak out-of-scope device data.
        $this->getJson('/api/outages')->assertForbidden();
        $this->getJson('/api/agents')->assertForbidden();
        $this->getJson('/api/alert-policies')->assertForbidden();
        $this->getJson('/api/users')->assertForbidden();
        // Config backups carry secrets - off-limits even for an in-scope device.
        $this->getJson("/api/devices/{$devA->id}/backups")->assertForbidden();
    }

    public function test_making_a_user_admin_clears_restriction(): void
    {
        $this->actingAsUser();
        [$mapA] = $this->twoMaps();
        $viewer = User::factory()->create(['is_admin' => false, 'restricted' => true]);
        $viewer->maps()->attach($mapA->id);

        $this->putJson("/api/users/{$viewer->id}", ['is_admin' => true])
            ->assertOk()
            ->assertJsonPath('restricted', false);
    }
}
