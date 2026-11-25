<?php

namespace Tests\Feature;

use App\Actions\Alerts\EvaluateAlerts;
use App\Enums\AlertCondition;
use App\Enums\DeviceStatus;
use App\Enums\DeviceType;
use App\Models\AlertEvent;
use App\Models\AlertPolicy;
use App\Models\AlertTransport;
use App\Models\Device;
use App\Models\DeviceMapPosition;
use App\Models\MaintenanceWindow;
use App\Models\Map;
use App\Models\Sensor;
use App\Models\User;
use App\Support\DeviceScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Targeting scopes (alert policies, maintenance windows, custom sensors): the original single
 * choice, and combinations of device types, maps and devices with exclusions.
 */
class DeviceScopeTest extends TestCase
{
    use RefreshDatabase;

    private Map $site;

    private Map $building;

    private Map $lab;

    /** @var array<string, Device> */
    private array $d = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Site > Building (a sub-map); Lab is separate.
        $this->site = Map::factory()->create(['name' => 'Site']);
        $this->building = Map::factory()->create(['name' => 'Building', 'parent_map_id' => $this->site->id]);
        $this->lab = Map::factory()->create(['name' => 'Lab']);

        $make = function (string $name, DeviceType $type, array $maps): Device {
            $device = Device::factory()->create(['name' => $name, 'device_type' => $type]);
            foreach ($maps as $map) {
                DeviceMapPosition::create(['device_id' => $device->id, 'map_id' => $map->id, 'x' => 0, 'y' => 0]);
            }

            return $this->d[$name] = $device;
        };

        $make('site-router', DeviceType::Router, [$this->site]);
        $make('site-switch', DeviceType::Switch, [$this->site]);
        $make('bldg-switch', DeviceType::Switch, [$this->building]);
        $make('bldg-ap', DeviceType::Ap, [$this->building]);
        $make('lab-router', DeviceType::Router, [$this->lab]);
        $make('loose-server', DeviceType::Server, []);
    }

    /** @param  list<string>  $names */
    private function ids(array $names): array
    {
        $ids = array_map(fn (string $n) => $this->d[$n]->id, $names);
        sort($ids);

        return $ids;
    }

    private function resolved(?array $scope): ?array
    {
        $ids = DeviceScope::resolve($scope);
        if ($ids !== null) {
            sort($ids);
        }

        return $ids;
    }

    public function test_the_original_single_choice_scopes_resolve_as_before(): void
    {
        $this->assertNull($this->resolved(null));
        $this->assertNull($this->resolved(['type' => 'all']));
        $this->assertSame($this->ids(['lab-router', 'site-router']), $this->resolved(['type' => 'device_type', 'device_type' => 'router']));
        // A single map: only devices placed directly on it, never its sub-maps.
        $this->assertSame($this->ids(['site-router', 'site-switch']), $this->resolved(['type' => 'map', 'map_id' => $this->site->id]));
        $this->assertSame($this->ids(['bldg-ap']), $this->resolved(['type' => 'devices', 'device_ids' => [$this->d['bldg-ap']->id]]));
    }

    public function test_types_are_or_ed_and_types_and_maps_are_and_ed(): void
    {
        // Routers OR switches, anywhere.
        $this->assertSame(
            $this->ids(['bldg-switch', 'lab-router', 'site-router', 'site-switch']),
            $this->resolved(['include' => ['device_types' => ['router', 'switch']]]),
        );
        // Site OR Lab, any type.
        $this->assertSame(
            $this->ids(['lab-router', 'site-router', 'site-switch']),
            $this->resolved(['include' => ['map_ids' => [$this->site->id, $this->lab->id]]]),
        );
        // Routers AND on Site or Lab.
        $this->assertSame(
            $this->ids(['lab-router', 'site-router']),
            $this->resolved(['include' => ['device_types' => ['router'], 'map_ids' => [$this->site->id, $this->lab->id]]]),
        );
    }

    public function test_sub_maps_are_off_unless_asked_for(): void
    {
        $off = ['include' => ['map_ids' => [$this->site->id]]];
        $on = ['include' => ['map_ids' => [$this->site->id], 'submaps' => true]];

        $this->assertSame($this->ids(['site-router', 'site-switch']), $this->resolved($off));
        $this->assertSame($this->ids(['bldg-ap', 'bldg-switch', 'site-router', 'site-switch']), $this->resolved($on));
    }

    public function test_listed_devices_are_added_whatever_the_rules_say(): void
    {
        $this->assertSame(
            $this->ids(['lab-router', 'loose-server', 'site-router']),
            $this->resolved(['include' => ['device_types' => ['router'], 'device_ids' => [$this->d['loose-server']->id]]]),
        );
        // Only listed devices: just those.
        $this->assertSame(
            $this->ids(['loose-server']),
            $this->resolved(['include' => ['device_ids' => [$this->d['loose-server']->id]]]),
        );
    }

    public function test_exclusions_by_type_map_and_device_always_win(): void
    {
        $all = Device::count();

        // Everything except APs.
        $this->assertCount($all - 1, $this->resolved(['exclude' => ['device_types' => ['ap']]]));
        // Everything except Lab, and except Site with its sub-maps.
        $this->assertSame($this->ids(['bldg-ap', 'bldg-switch', 'loose-server', 'site-router', 'site-switch']), $this->resolved(['exclude' => ['map_ids' => [$this->lab->id]]]));
        $this->assertSame($this->ids(['lab-router', 'loose-server']), $this->resolved(['exclude' => ['map_ids' => [$this->site->id], 'submaps' => true]]));
        // Switches on Site with its sub-maps, except one listed device and except the Building map.
        $this->assertSame(
            $this->ids(['site-switch']),
            $this->resolved([
                'include' => ['device_types' => ['switch'], 'map_ids' => [$this->site->id], 'submaps' => true],
                'exclude' => ['map_ids' => [$this->building->id]],
            ]),
        );
        // An exclusion beats a device listed for inclusion.
        $this->assertSame([], $this->resolved([
            'include' => ['device_ids' => [$this->d['lab-router']->id]],
            'exclude' => ['device_types' => ['router']],
        ]));
        // Several types, maps and devices in one exclusion: any of them takes a device out.
        $this->assertSame($this->ids(['bldg-ap']), $this->resolved([
            'exclude' => [
                'device_types' => ['router', 'server'],
                'map_ids' => [$this->site->id, $this->lab->id],
                'device_ids' => [$this->d['bldg-switch']->id],
            ],
        ]));
    }

    public function test_an_empty_combination_is_fleet_wide(): void
    {
        $this->assertNull($this->resolved(['include' => [], 'exclude' => []]));
        $this->assertNull(DeviceScope::normalize(['include' => ['device_types' => [], 'map_ids' => []], 'exclude' => []]));
    }

    public function test_normalize_keeps_known_fields_only(): void
    {
        $this->assertNull(DeviceScope::normalize(null));
        $this->assertNull(DeviceScope::normalize(['type' => 'all', 'junk' => 1]));
        $this->assertSame(['type' => 'map', 'map_id' => 7], DeviceScope::normalize(['type' => 'map', 'map_id' => '7', 'extra' => 'x']));
        $this->assertSame(
            [
                'include' => ['device_types' => ['router', 'switch'], 'map_ids' => [3, 9], 'submaps' => true, 'device_ids' => []],
                'exclude' => ['device_types' => [], 'map_ids' => [], 'submaps' => false, 'device_ids' => [4]],
            ],
            DeviceScope::normalize([
                'include' => ['device_types' => ['switch', 'router', 'switch', 'toaster'], 'map_ids' => [9, '3', 9], 'submaps' => '1', 'other' => 1],
                'exclude' => ['device_ids' => [4, 4, 0, -2]],
            ]),
        );
    }

    public function test_alert_policies_save_and_return_a_combination_scope(): void
    {
        $this->actingAsUser();
        $scope = [
            'include' => ['device_types' => ['router', 'switch'], 'map_ids' => [$this->site->id], 'submaps' => false, 'device_ids' => []],
            'exclude' => ['device_types' => ['ap'], 'map_ids' => [$this->lab->id], 'submaps' => true, 'device_ids' => [$this->d['loose-server']->id]],
        ];

        $id = $this->postJson('/api/alert-policies', ['name' => 'Core down', 'condition' => 'device_down', 'scope' => $scope])
            ->assertCreated()
            ->assertJsonPath('data.scope.include.device_types', ['router', 'switch'])
            ->assertJsonPath('data.scope.exclude.map_ids', [$this->lab->id])
            ->json('data.id');
        $this->assertSame($scope, AlertPolicy::find($id)->scope);

        // Back to fleet-wide.
        $this->putJson("/api/alert-policies/{$id}", ['scope' => ['include' => [], 'exclude' => []]])->assertOk();
        $this->assertNull(AlertPolicy::find($id)->scope);
    }

    public function test_combination_scopes_are_validated(): void
    {
        $this->actingAsUser();
        $post = fn (array $scope) => $this->postJson('/api/alert-policies', ['name' => 'P', 'condition' => 'device_down', 'scope' => $scope]);

        $post(['include' => ['device_types' => ['toaster']]])->assertStatus(422)->assertJsonValidationErrors('scope.include.device_types.0');
        $post(['exclude' => ['map_ids' => [999999]]])->assertStatus(422)->assertJsonValidationErrors('scope.exclude.map_ids.0');
        $post(['include' => ['device_ids' => [999999]]])->assertStatus(422)->assertJsonValidationErrors('scope.include.device_ids.0');
        $post(['include' => ['submaps' => 'sometimes']])->assertStatus(422)->assertJsonValidationErrors('scope.include.submaps');
        // The two shapes can't be mixed.
        $post(['type' => 'map', 'map_id' => $this->site->id, 'include' => ['device_types' => ['router']]])
            ->assertStatus(422)->assertJsonValidationErrors('scope.type');
        // The original shape still validates as before.
        $post(['type' => 'device_type', 'device_type' => 'toaster'])->assertStatus(422)->assertJsonValidationErrors('scope.device_type');
    }

    public function test_maintenance_windows_and_sensors_take_combination_scopes_too(): void
    {
        $this->actingAsUser();
        $scope = ['include' => ['map_ids' => [$this->site->id], 'submaps' => true], 'exclude' => ['device_types' => ['ap']]];

        $w = $this->postJson('/api/maintenance-windows', [
            'name' => 'Site work', 'starts_at' => now()->subMinute()->toIso8601String(), 'ends_at' => now()->addHour()->toIso8601String(), 'scope' => $scope,
        ])->assertCreated()->json('data.id');
        $this->assertSame($this->ids(['bldg-switch', 'site-router', 'site-switch']), $this->resolved(MaintenanceWindow::find($w)->scope));

        $s = $this->postJson('/api/sensors', ['name' => 'Uptime', 'oid' => '1.3.6.1.2.1.1.3.0', 'scope' => $scope])
            ->assertCreated()->json('data.id');
        $this->assertSame($this->ids(['bldg-switch', 'site-router', 'site-switch']), $this->resolved(Sensor::find($s)->scope));
    }

    public function test_the_preview_counts_the_devices_a_scope_covers(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/scopes/preview', ['scope' => ['include' => ['device_types' => ['router']], 'exclude' => ['map_ids' => [$this->lab->id]]]])
            ->assertOk()
            ->assertJsonPath('data.all', false)
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.total', 6)
            ->assertJsonPath('data.sample.0.name', 'site-router');

        $this->postJson('/api/scopes/preview', ['scope' => null])
            ->assertOk()->assertJsonPath('data.all', true)->assertJsonPath('data.count', 6);

        $this->postJson('/api/scopes/preview', ['scope' => ['include' => ['device_types' => ['toaster']]]])->assertStatus(422);
    }

    public function test_the_preview_is_for_admins_only(): void
    {
        $this->actingAs(User::factory()->create()); // an ordinary operator
        $this->postJson('/api/scopes/preview', ['scope' => null])->assertForbidden();
    }

    public function test_an_excluded_device_doesnt_alert(): void
    {
        Http::fake();
        $policy = AlertPolicy::factory()->create([
            'condition' => AlertCondition::DeviceDown,
            'scope' => ['include' => ['map_ids' => [$this->site->id], 'submaps' => true], 'exclude' => ['device_types' => ['ap']]],
        ]);
        $policy->transports()->attach(AlertTransport::factory()->create());

        foreach (['bldg-ap', 'bldg-switch', 'lab-router'] as $name) {
            $this->d[$name]->update(['status' => DeviceStatus::Down]);
        }
        app(EvaluateAlerts::class)();

        // Only the Building switch: the AP is excluded and the Lab router is out of scope.
        $this->assertSame(["device:{$this->d['bldg-switch']->id}"], AlertEvent::where('alert_policy_id', $policy->id)->pluck('dedupe_key')->all());
    }
}
