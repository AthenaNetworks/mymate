<?php

namespace Tests\Feature;

use App\Enums\DeviceStatus;
use App\Enums\PollMethod;
use App\Enums\UpgradeStatus;
use App\Events\DeviceStatusChanged;
use App\Models\Device;
use App\Models\DeviceMapPosition;
use App\Models\Map;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The fleet-scale device endpoints (GitHub #22): a paged/searchable `GET /devices`, the
 * `/devices/stats` header counts, a single `GET /devices/{id}` and `GET /maps/{map}/devices`.
 * Each one has to respect the restricted-operator visibility scope, so that's tested per endpoint.
 */
class DeviceListApiTest extends TestCase
{
    use RefreshDatabase;

    private function place(Device $device, Map $map): void
    {
        DeviceMapPosition::create(['device_id' => $device->id, 'map_id' => $map->id, 'x' => 0, 'y' => 0]);
    }

    /** A restricted operator granted just $map. */
    private function restrictedTo(Map $map): User
    {
        $user = User::factory()->create(['is_admin' => false, 'restricted' => true]);
        $user->maps()->attach($map->id);

        return $user;
    }

    public function test_index_is_paginated_with_laravel_meta(): void
    {
        $this->actingAsUser();
        Device::factory()->count(7)->create();

        $res = $this->getJson('/api/devices?per_page=3&page=2')->assertOk();

        $res->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 3)
            ->assertJsonPath('meta.total', 7)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonStructure(['data', 'links' => ['first', 'last', 'prev', 'next'], 'meta']);
    }

    public function test_index_defaults_to_fifty_per_page_and_caps_per_page(): void
    {
        $this->actingAsUser();
        Device::factory()->count(55)->create();

        $this->getJson('/api/devices')->assertOk()->assertJsonCount(50, 'data')->assertJsonPath('meta.total', 55);
        $this->getJson('/api/devices?per_page=201')->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->getJson('/api/devices?sort=secret_column')->assertUnprocessable()->assertJsonValidationErrors('sort');
    }

    public function test_pages_do_not_overlap_and_sort_by_name(): void
    {
        $this->actingAsUser();
        foreach (['delta', 'alpha', 'charlie', 'bravo', 'echo'] as $name) {
            Device::factory()->create(['name' => $name]);
        }

        $p1 = collect($this->getJson('/api/devices?per_page=2&page=1')->json('data'))->pluck('name')->all();
        $p2 = collect($this->getJson('/api/devices?per_page=2&page=2')->json('data'))->pluck('name')->all();
        $p3 = collect($this->getJson('/api/devices?per_page=2&page=3')->json('data'))->pluck('name')->all();

        $this->assertSame(['alpha', 'bravo', 'charlie', 'delta', 'echo'], [...$p1, ...$p2, ...$p3]);

        $desc = collect($this->getJson('/api/devices?sort=-name')->json('data'))->pluck('name')->all();
        $this->assertSame(['echo', 'delta', 'charlie', 'bravo', 'alpha'], $desc);
    }

    public function test_status_sort_puts_down_first(): void
    {
        $this->actingAsUser();
        Device::factory()->create(['name' => 'a-up', 'status' => DeviceStatus::Up]);
        Device::factory()->create(['name' => 'b-unknown', 'status' => DeviceStatus::Unknown]);
        Device::factory()->create(['name' => 'c-down', 'status' => DeviceStatus::Down]);

        $names = collect($this->getJson('/api/devices?sort=status')->json('data'))->pluck('name')->all();

        $this->assertSame(['c-down', 'b-unknown', 'a-up'], $names);
    }

    public function test_search_matches_name_ip_vendor_and_model_case_insensitively(): void
    {
        $this->actingAsUser();
        $byName = Device::factory()->create(['name' => 'Tower-North-AP']);
        $byIp = Device::factory()->create(['name' => 'x1', 'mgmt_ip' => '10.99.1.7']);
        $byVendor = Device::factory()->create(['name' => 'x2', 'vendor' => 'Ubiquiti']);
        $byModel = Device::factory()->create(['name' => 'x3', 'model' => 'CCR2004-16G']);
        Device::factory()->create(['name' => 'unrelated', 'mgmt_ip' => '192.0.2.1', 'vendor' => null, 'model' => null]);

        $ids = fn (string $q) => collect($this->getJson('/api/devices?q='.urlencode($q))->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$byName->id], $ids('north'));
        $this->assertSame([$byIp->id], $ids('10.99.1'));
        $this->assertSame([$byVendor->id], $ids('ubiq'));
        $this->assertSame([$byModel->id], $ids('ccr2004'));
        // LIKE wildcards in the search are literal, not patterns.
        $this->assertSame([], $ids('%'));
    }

    public function test_filters(): void
    {
        $this->actingAsUser();
        $map = Map::factory()->create();
        $down = Device::factory()->create(['status' => DeviceStatus::Down, 'poll_method' => PollMethod::Snmp]);
        $paused = Device::factory()->create(['monitored' => false, 'poll_method' => PollMethod::Snmp]);
        $ros = Device::factory()->create(['poll_method' => PollMethod::RouterOs, 'upgrade_status' => UpgradeStatus::Downloading]);
        $backedUp = Device::factory()->create(['poll_method' => PollMethod::Snmp, 'backup_enabled' => true, 'parent_device_id' => $down->id]);
        $this->place($down, $map);

        $ids = fn (string $qs) => collect($this->getJson('/api/devices?'.$qs)->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

        $this->assertSame([$down->id], $ids('status=down'));
        $this->assertSame([$paused->id], $ids('monitored=0'));
        $this->assertSame([$ros->id], $ids('poll_method=routeros'));
        $this->assertSame([$ros->id], $ids('upgrading=1'));
        $this->assertSame([$backedUp->id], $ids('backup_enabled=1'));
        $this->assertSame([$backedUp->id], $ids('parent_id='.$down->id));
        $this->assertSame([$down->id], $ids('placed=1'));
        $this->assertSame([$down->id], $ids('map_id='.$map->id));
        $this->assertNotContains($down->id, $ids('not_on_map='.$map->id));
        $this->assertSame([$paused->id, $ros->id], $ids('ids[]='.$paused->id.'&ids[]='.$ros->id));
    }

    public function test_not_under_leaves_out_the_device_and_its_descendants(): void
    {
        $this->actingAsUser();
        $root = Device::factory()->create();
        $child = Device::factory()->create(['parent_device_id' => $root->id]);
        $grandchild = Device::factory()->create(['parent_device_id' => $child->id]);
        $other = Device::factory()->create();

        $ids = collect($this->getJson('/api/devices?not_under='.$child->id)->json('data'))->pluck('id')->sort()->values()->all();

        $this->assertSame([$root->id, $other->id], $ids);
        $this->assertNotContains($grandchild->id, $ids);
    }

    public function test_not_under_survives_a_parent_loop_in_the_data(): void
    {
        $this->actingAsUser();
        $a = Device::factory()->create();
        $b = Device::factory()->create(['parent_device_id' => $a->id]);
        $a->forceFill(['parent_device_id' => $b->id])->saveQuietly();
        $other = Device::factory()->create();

        $ids = collect($this->getJson('/api/devices?not_under='.$a->id)->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$other->id], $ids);
    }

    public function test_geo_unplaced_follows_own_site_and_uplink_coordinates(): void
    {
        $this->actingAsUser();
        $site = Site::create(['name' => 'Hill', 'latitude' => -27.5, 'longitude' => 153.0]);
        $pinned = Device::factory()->create(['latitude' => -27.4, 'longitude' => 153.1]);
        $atSite = Device::factory()->create(['site_id' => $site->id]);
        $inherits = Device::factory()->create(['parent_device_id' => $pinned->id]);
        $lost = Device::factory()->create();

        $unplaced = collect($this->getJson('/api/devices?geo=unplaced')->json('data'))->pluck('id')->all();
        $placed = collect($this->getJson('/api/devices?geo=placed')->json('data'))->pluck('id')->sort()->values()->all();

        $this->assertSame([$lost->id], $unplaced);
        $this->assertSame(collect([$pinned->id, $atSite->id, $inherits->id])->sort()->values()->all(), $placed);
    }

    public function test_page_rows_inherit_coordinates_from_a_parent_on_another_page(): void
    {
        $this->actingAsUser();
        $tower = Device::factory()->create(['name' => 'zz-tower', 'latitude' => -27.4, 'longitude' => 153.1]);
        $cpe = Device::factory()->create(['name' => 'aa-cpe', 'parent_device_id' => $tower->id]);

        // The CPE sorts first; the tower is on page 2.
        $row = $this->getJson('/api/devices?per_page=1')->assertOk()->json('data.0');

        $this->assertSame($cpe->id, $row['id']);
        $this->assertSame(-27.4, $row['geo_latitude']);
        $this->assertTrue($row['geo_inherited']);
    }

    public function test_summary_rows_are_lean(): void
    {
        $this->actingAsUser();
        $parent = Device::factory()->create(['name' => 'core']);
        Device::factory()->create(['name' => 'edge', 'parent_device_id' => $parent->id, 'cpu_pct' => 12]);

        $row = collect($this->getJson('/api/devices?fields=summary')->assertOk()->json('data'))->firstWhere('name', 'edge');

        $this->assertSame('core', $row['parent_name']);
        $this->assertArrayHasKey('poll_method', $row);
        $this->assertArrayNotHasKey('cpu_pct', $row);
        $this->assertArrayNotHasKey('backup_status', $row);
    }

    public function test_stats_count_monitored_devices_by_status_and_paused_separately(): void
    {
        $this->actingAsUser();
        Device::factory()->count(3)->create(['status' => DeviceStatus::Up]);
        Device::factory()->count(2)->create(['status' => DeviceStatus::Down]);
        Device::factory()->create(['status' => DeviceStatus::Unknown]);
        Device::factory()->create(['status' => DeviceStatus::Down, 'monitored' => false]);

        $this->getJson('/api/devices/stats')->assertOk()->assertExactJson(['data' => [
            'up' => 3, 'down' => 2, 'unknown' => 1, 'paused' => 1, 'total' => 7,
        ]]);
    }

    public function test_show_returns_one_device(): void
    {
        $this->actingAsUser();
        $tower = Device::factory()->create(['latitude' => -27.4, 'longitude' => 153.1]);
        $cpe = Device::factory()->create(['name' => 'cpe', 'parent_device_id' => $tower->id]);

        $this->getJson("/api/devices/{$cpe->id}")->assertOk()
            ->assertJsonPath('data.id', $cpe->id)
            ->assertJsonPath('data.parent_name', $tower->name)
            ->assertJsonPath('data.geo_latitude', -27.4) // uplink inheritance now works here too
            ->assertJsonPath('data.maps_count', 0);
        $this->getJson('/api/devices/999999')->assertNotFound();
    }

    public function test_map_devices_returns_only_that_maps_devices(): void
    {
        $this->actingAsUser();
        $map = Map::factory()->create();
        $other = Map::factory()->create();
        $on = Device::factory()->create(['name' => 'on-map']);
        $off = Device::factory()->create(['name' => 'elsewhere']);
        $this->place($on, $map);
        $this->place($off, $other);

        $rows = $this->getJson("/api/maps/{$map->id}/devices")->assertOk()->json('data');

        $this->assertSame([$on->id], collect($rows)->pluck('id')->all());
        $this->assertSame(1, $rows[0]['maps_count']);
    }

    public function test_every_endpoint_respects_restricted_visibility(): void
    {
        $mapA = Map::factory()->create();
        $mapB = Map::factory()->create();
        $mine = Device::factory()->create(['name' => 'mine', 'status' => DeviceStatus::Up]);
        $theirs = Device::factory()->create(['name' => 'theirs', 'status' => DeviceStatus::Down]);
        $unplaced = Device::factory()->create(['name' => 'nowhere', 'status' => DeviceStatus::Down]);
        $this->place($mine, $mapA);
        $this->place($theirs, $mapB);

        $this->actingAs($this->restrictedTo($mapA));

        // The list, however it's asked for.
        $this->assertSame([$mine->id], collect($this->getJson('/api/devices')->assertOk()->json('data'))->pluck('id')->all());
        $this->getJson('/api/devices')->assertJsonPath('meta.total', 1);
        $this->assertSame([], $this->getJson('/api/devices?q=theirs')->json('data'));
        $this->assertSame([], $this->getJson('/api/devices?status=down')->json('data'));
        $this->assertSame([], $this->getJson('/api/devices?placed=0')->json('data'));
        $this->assertSame([], $this->getJson("/api/devices?map_id={$mapB->id}")->json('data'));
        $asked = collect($this->getJson("/api/devices?ids[]={$theirs->id}&ids[]={$mine->id}&ids[]={$unplaced->id}&fields=summary")->json('data'));
        $this->assertSame([$mine->id], $asked->pluck('id')->all());

        // Counts only cover what they can see.
        $this->getJson('/api/devices/stats')->assertOk()->assertJsonPath('data.up', 1)
            ->assertJsonPath('data.down', 0)->assertJsonPath('data.total', 1);

        // Single device and the other map 404.
        $this->getJson("/api/devices/{$mine->id}")->assertOk();
        $this->getJson("/api/devices/{$theirs->id}")->assertNotFound();
        $this->getJson("/api/maps/{$mapA->id}/devices")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/maps/{$mapB->id}/devices")->assertNotFound();
    }

    public function test_unrestricted_operator_still_sees_everything(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        Device::factory()->count(3)->create();

        $this->getJson('/api/devices')->assertOk()->assertJsonPath('meta.total', 3);
        $this->getJson('/api/devices/stats')->assertOk()->assertJsonPath('data.total', 3);
    }

    public function test_status_event_carries_name_and_previous_status(): void
    {
        $device = Device::factory()->create(['name' => 'edge-7', 'status' => DeviceStatus::Up]);
        $device->forceFill(['status' => DeviceStatus::Down, 'last_change' => now()])->save();

        $payload = (new DeviceStatusChanged($device))->broadcastWith();

        $this->assertSame('edge-7', $payload['name']);
        $this->assertSame('up', $payload['previous_status']);
        $this->assertSame('down', $payload['status']);
        $this->assertTrue($payload['monitored']);
    }
}
