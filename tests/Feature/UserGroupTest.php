<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceMapPosition;
use App\Models\Link;
use App\Models\Map;
use App\Models\NetworkInterface;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Named operator groups (GitHub #28). What matters here is that restriction coming from a group
 * is enforced everywhere the per-user one is (scopes, route binding, RestrictedAccess, /api/user),
 * that the precedence rules fail closed, and that only an admin can touch groups or membership.
 */
class UserGroupTest extends TestCase
{
    use RefreshDatabase;

    /** Two maps, each with one device. Returns [mapA, devA, mapB, devB]. */
    private function twoMaps(): array
    {
        $mapA = Map::factory()->create(['name' => 'North']);
        $mapB = Map::factory()->create(['name' => 'South']);
        $devA = Device::factory()->create(['name' => 'north-core']);
        $devB = Device::factory()->create(['name' => 'south-core']);
        DeviceMapPosition::create(['device_id' => $devA->id, 'map_id' => $mapA->id, 'x' => 0, 'y' => 0]);
        DeviceMapPosition::create(['device_id' => $devB->id, 'map_id' => $mapB->id, 'x' => 0, 'y' => 0]);

        return [$mapA, $devA, $mapB, $devB];
    }

    /** @param  array<Map>  $maps */
    private function group(string $name, bool $restricted, array $maps = []): UserGroup
    {
        $group = new UserGroup(['name' => $name]);
        $group->restricted = $restricted;
        $group->save();
        $group->maps()->attach(array_map(fn (Map $m) => $m->id, $maps));

        return $group;
    }

    /** A plain viewer - NOT individually restricted - so any restriction has to come from the group. */
    private function memberOf(UserGroup ...$groups): User
    {
        $user = User::factory()->create(['is_admin' => false, 'restricted' => false]);
        foreach ($groups as $g) {
            $user->groups()->attach($g->id);
        }

        return $user;
    }

    private function deviceIds(): array
    {
        return collect($this->getJson('/api/devices')->assertOk()->json('data'))->pluck('id')->all();
    }

    private function mapIds(): array
    {
        return collect($this->getJson('/api/maps')->assertOk()->json('data'))->pluck('id')->all();
    }

    public function test_group_restricted_user_only_sees_the_groups_maps_and_devices(): void
    {
        [$mapA, $devA, $mapB, $devB] = $this->twoMaps();
        $this->actingAs($this->memberOf($this->group('Field techs - North', true, [$mapA])));

        $this->assertEqualsCanonicalizing([$mapA->id], $this->mapIds());
        $this->assertEqualsCanonicalizing([$devA->id], $this->deviceIds());
        $this->getJson('/api/user')->assertOk()->assertJsonPath('restricted', true);
    }

    public function test_out_of_group_maps_devices_and_sub_resources_404(): void
    {
        [$mapA, $devA, $mapB, $devB] = $this->twoMaps();
        NetworkInterface::factory()->create(['device_id' => $devB->id]);
        $this->actingAs($this->memberOf($this->group('North', true, [$mapA])));

        $this->getJson("/api/maps/{$mapA->id}")->assertOk();
        $this->getJson("/api/maps/{$mapB->id}")->assertNotFound();
        $this->getJson("/api/devices/{$devA->id}")->assertOk();
        $this->getJson("/api/devices/{$devB->id}")->assertNotFound();
        $this->getJson("/api/devices/{$devB->id}/interfaces")->assertNotFound();
        $this->getJson("/api/devices/{$devB->id}/probes")->assertNotFound();
    }

    public function test_links_crossing_out_of_the_group_are_hidden(): void
    {
        [$mapA, $devA, $mapB, $devB] = $this->twoMaps();
        $devA2 = Device::factory()->create();
        DeviceMapPosition::create(['device_id' => $devA2->id, 'map_id' => $mapA->id, 'x' => 1, 'y' => 1]);
        $inside = Link::create(['a_device_id' => $devA->id, 'b_device_id' => $devA2->id]);
        $crossing = Link::create(['a_device_id' => $devA->id, 'b_device_id' => $devB->id]);

        $this->actingAs($this->memberOf($this->group('North', true, [$mapA])));

        $ids = collect($this->getJson('/api/links')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertContains($inside->id, $ids);
        $this->assertNotContains($crossing->id, $ids);
    }

    public function test_group_restricted_user_is_confined_to_map_viewing_endpoints(): void
    {
        [$mapA, $devA] = $this->twoMaps();
        $this->actingAs($this->memberOf($this->group('North', true, [$mapA])));

        $this->getJson('/api/outages')->assertForbidden();
        $this->getJson('/api/agents')->assertForbidden();
        $this->getJson('/api/users')->assertForbidden();
        $this->getJson('/api/user-groups')->assertForbidden();
        $this->getJson("/api/devices/{$devA->id}/backups")->assertForbidden();
    }

    public function test_a_groups_parent_map_brings_its_sub_maps(): void
    {
        [$mapA, $devA, $mapB, $devB] = $this->twoMaps();
        $mapB->update(['parent_map_id' => $mapA->id]);
        $this->actingAs($this->memberOf($this->group('Region', true, [$mapA])));

        $this->assertEqualsCanonicalizing([$devA->id, $devB->id], $this->deviceIds());
    }

    public function test_maps_from_several_restricted_groups_and_own_grants_are_unioned(): void
    {
        [$mapA, $devA, $mapB, $devB] = $this->twoMaps();
        $mapC = Map::factory()->create();
        $devC = Device::factory()->create();
        DeviceMapPosition::create(['device_id' => $devC->id, 'map_id' => $mapC->id, 'x' => 0, 'y' => 0]);

        // Two groups -> A + B.
        $this->actingAs($this->memberOf($this->group('North', true, [$mapA]), $this->group('South', true, [$mapB])));
        $this->assertEqualsCanonicalizing([$devA->id, $devB->id], $this->deviceIds());

        // Individually restricted to C plus a group granting A -> A + C.
        $user = User::factory()->create(['is_admin' => false, 'restricted' => true]);
        $user->maps()->attach($mapC->id);
        $user->groups()->attach($this->group('North 2', true, [$mapA])->id);
        $this->actingAs($user);
        $this->assertEqualsCanonicalizing([$devA->id, $devC->id], $this->deviceIds());
    }

    public function test_most_restrictive_wins_when_mixing_a_read_only_group_and_a_restricted_one(): void
    {
        [$mapA, $devA, $mapB, $devB] = $this->twoMaps();
        $this->actingAs($this->memberOf($this->group('NOC', false), $this->group('North', true, [$mapA])));

        $this->assertEqualsCanonicalizing([$devA->id], $this->deviceIds());
    }

    public function test_a_read_only_group_on_its_own_sees_everything(): void
    {
        [$mapA, $devA, $mapB, $devB] = $this->twoMaps();
        $this->actingAs($this->memberOf($this->group('NOC', false)));

        $this->assertEqualsCanonicalizing([$devA->id, $devB->id], $this->deviceIds());
        $this->getJson('/api/user')->assertJsonPath('restricted', false);
        // Still read-only, a group never grants writes.
        $this->deleteJson("/api/devices/{$devA->id}")->assertForbidden();
    }

    public function test_an_individually_restricted_user_cant_be_widened_by_a_read_only_group(): void
    {
        [$mapA, $devA, $mapB, $devB] = $this->twoMaps();
        $user = User::factory()->create(['is_admin' => false, 'restricted' => true]);
        $user->maps()->attach($mapA->id);
        $user->groups()->attach($this->group('NOC', false)->id);
        $this->actingAs($user);

        $this->assertEqualsCanonicalizing([$devA->id], $this->deviceIds());
    }

    public function test_stale_own_grants_dont_count_unless_the_user_is_individually_restricted(): void
    {
        [$mapA, $devA, $mapB, $devB] = $this->twoMaps();
        // Left over from when they used to be restricted; only the group's map should apply now.
        $user = $this->memberOf($this->group('North', true, [$mapA]));
        $user->maps()->attach($mapB->id);
        $this->actingAs($user);

        $this->assertEqualsCanonicalizing([$devA->id], $this->deviceIds());
    }

    public function test_an_empty_restricted_group_sees_nothing(): void
    {
        $this->twoMaps();
        $this->actingAs($this->memberOf($this->group('Nobody yet', true)));

        $this->assertSame([], $this->deviceIds());
        $this->assertSame([], $this->mapIds());
    }

    public function test_admins_are_never_scoped_by_a_group(): void
    {
        [$mapA, $devA, $mapB, $devB] = $this->twoMaps();
        // Not reachable through the API (store/update refuse it) but the model must still hold.
        $admin = User::factory()->admin()->create();
        $admin->groups()->attach($this->group('North', true, [$mapA])->id);
        $this->actingAs($admin);

        $this->assertEqualsCanonicalizing([$devA->id, $devB->id], $this->deviceIds());
    }

    public function test_users_with_no_groups_resolve_exactly_as_before(): void
    {
        [$mapA, $devA, $mapB, $devB] = $this->twoMaps();
        $this->group('North', true, [$mapA]); // exists, but they aren't in it

        $this->actingAs(User::factory()->create(['is_admin' => false, 'restricted' => false]));
        $this->assertEqualsCanonicalizing([$devA->id, $devB->id], $this->deviceIds());

        $restricted = User::factory()->create(['is_admin' => false, 'restricted' => true]);
        $restricted->maps()->attach($mapB->id);
        $this->actingAs($restricted);
        $this->assertEqualsCanonicalizing([$devB->id], $this->deviceIds());
    }

    public function test_background_queries_are_never_scoped_by_groups(): void
    {
        [$mapA, $devA, $mapB, $devB] = $this->twoMaps();
        $this->memberOf($this->group('North', true, [$mapA]));

        $this->assertEqualsCanonicalizing([$devA->id, $devB->id], Device::pluck('id')->all());
    }

    // --- management: admin only ---

    public function test_non_admins_cannot_read_or_manage_groups(): void
    {
        [$mapA, $devA, $mapB] = $this->twoMaps();
        $group = $this->group('North', true, [$mapA]);
        $viewer = User::factory()->create(['is_admin' => false, 'restricted' => false]);
        $this->actingAs($viewer);

        $this->getJson('/api/user-groups')->assertForbidden();
        $this->postJson('/api/user-groups', ['name' => 'Mine', 'restricted' => false, 'user_ids' => [$viewer->id]])->assertForbidden();
        $this->putJson("/api/user-groups/{$group->id}", ['restricted' => false])->assertForbidden();
        $this->putJson("/api/user-groups/{$group->id}", ['map_ids' => [$mapA->id, $mapB->id]])->assertForbidden();
        $this->deleteJson("/api/user-groups/{$group->id}")->assertForbidden();

        $this->assertDatabaseCount('user_groups', 1);
        $this->assertTrue(UserGroup::find($group->id)->restricted);
        $this->assertSame([$mapA->id], UserGroup::find($group->id)->maps()->pluck('maps.id')->all());
    }

    public function test_a_non_admin_cannot_move_themselves_between_groups(): void
    {
        [$mapA, $devA, $mapB] = $this->twoMaps();
        $north = $this->group('North', true, [$mapA]);
        $noc = $this->group('NOC', false);
        $user = $this->memberOf($north);
        $this->actingAs($user);

        // Out of the restricted group and into the read-everything one, every way we can think of.
        $this->putJson("/api/users/{$user->id}", ['group_ids' => [$noc->id]])->assertForbidden();
        $this->putJson("/api/user-groups/{$noc->id}", ['user_ids' => [$user->id]])->assertForbidden();
        $this->putJson("/api/user-groups/{$north->id}", ['user_ids' => []])->assertForbidden();
        $this->putJson('/api/account/password', [
            'current_password' => 'password', 'password' => 'NewPassw0rd99', 'password_confirmation' => 'NewPassw0rd99',
            'group_ids' => [$noc->id],
        ]);

        $this->assertEqualsCanonicalizing([$north->id], $user->groups()->pluck('user_groups.id')->all());
    }

    public function test_admin_can_create_and_edit_a_group_and_membership_takes_effect(): void
    {
        [$mapA, $devA, $mapB, $devB] = $this->twoMaps();
        $member = User::factory()->create(['is_admin' => false, 'restricted' => false]);
        $this->actingAsUser();

        $id = $this->postJson('/api/user-groups', [
            'name' => 'Field techs - North',
            'restricted' => true,
            'map_ids' => [$mapA->id],
            'user_ids' => [$member->id],
        ])->assertCreated()
            ->assertJsonPath('restricted', true)
            ->assertJsonPath('map_ids', [$mapA->id])
            ->assertJsonPath('user_ids', [$member->id])
            ->json('id');

        $this->getJson('/api/user-groups')->assertOk()->assertJsonPath('0.name', 'Field techs - North');
        $this->getJson('/api/users')->assertOk();
        $this->assertSame([$id], collect($this->getJson('/api/users')->json())->firstWhere('id', $member->id)['group_ids']);

        $this->actingAs(User::find($member->id));
        $this->assertEqualsCanonicalizing([$devA->id], $this->deviceIds());

        $this->actingAsUser();
        $this->putJson("/api/user-groups/{$id}", ['map_ids' => [$mapA->id, $mapB->id]])->assertOk();
        $this->actingAs(User::find($member->id));
        $this->assertEqualsCanonicalizing([$devA->id, $devB->id], $this->deviceIds());
    }

    public function test_a_new_group_defaults_to_restricted(): void
    {
        $this->actingAsUser();
        $this->postJson('/api/user-groups', ['name' => 'Customers'])->assertCreated()->assertJsonPath('restricted', true);
    }

    public function test_group_names_are_unique(): void
    {
        $this->actingAsUser();
        $this->group('NOC', false);
        $this->postJson('/api/user-groups', ['name' => 'NOC'])->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_admins_cannot_be_put_in_a_group(): void
    {
        $admin = $this->actingAsUser();
        $group = $this->group('North', true);

        $this->putJson("/api/user-groups/{$group->id}", ['user_ids' => [$admin->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('user_ids');
        $this->putJson("/api/users/{$admin->id}", ['group_ids' => [$group->id]])->assertOk()->assertJsonPath('group_ids', []);
    }

    public function test_admin_can_assign_groups_from_the_user_form_and_promotion_clears_them(): void
    {
        $group = $this->group('North', true);
        $viewer = User::factory()->create(['is_admin' => false]);
        $this->actingAsUser();

        $this->putJson("/api/users/{$viewer->id}", ['group_ids' => [$group->id]])
            ->assertOk()->assertJsonPath('group_ids', [$group->id]);
        $this->assertDatabaseHas('user_group_user', ['user_id' => $viewer->id, 'user_group_id' => $group->id]);

        $this->putJson("/api/users/{$viewer->id}", ['is_admin' => true])->assertOk()->assertJsonPath('group_ids', []);
        $this->assertDatabaseMissing('user_group_user', ['user_id' => $viewer->id]);
    }

    public function test_a_group_with_members_cannot_be_deleted(): void
    {
        $group = $this->group('North', true);
        $member = $this->memberOf($group);
        $this->actingAsUser();

        $this->deleteJson("/api/user-groups/{$group->id}")->assertUnprocessable();
        $this->assertDatabaseHas('user_groups', ['id' => $group->id]);

        $member->groups()->detach();
        $this->deleteJson("/api/user-groups/{$group->id}")->assertNoContent();
        $this->assertDatabaseMissing('user_groups', ['id' => $group->id]);
    }
}
