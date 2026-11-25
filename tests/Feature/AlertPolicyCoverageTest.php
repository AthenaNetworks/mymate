<?php

namespace Tests\Feature;

use App\Models\AlertPolicy;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The policy list says what each policy's scope covers: how many devices it matches, a few of
 * their names, and the names of devices it lists one by one, so the Alerts page can show them.
 */
class AlertPolicyCoverageTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> the listed policy named $name */
    private function listed(string $name): array
    {
        $rows = $this->getJson('/api/alert-policies')->assertOk()->json('data');

        return collect($rows)->firstWhere('name', $name);
    }

    public function test_a_combined_scope_reports_its_matches_and_names_its_listed_devices(): void
    {
        $this->actingAsUser();
        Device::factory()->create(['name' => 'core-r1', 'device_type' => 'router']);
        Device::factory()->create(['name' => 'core-r2', 'device_type' => 'router']);
        $lab = Device::factory()->create(['name' => 'lab-r1', 'device_type' => 'router']);
        $gw = Device::factory()->create(['name' => 'edge-gw-01', 'device_type' => 'switch']);
        Device::factory()->create(['name' => 'ap-1', 'device_type' => 'ap']);

        AlertPolicy::factory()->create(['name' => 'Core', 'scope' => [
            'include' => ['device_types' => ['router'], 'device_ids' => [$gw->id]],
            'exclude' => ['device_ids' => [$lab->id]],
        ]]);

        $c = $this->listed('Core')['coverage'];
        $this->assertSame(3, $c['matched']); // two routers (lab excepted) plus the listed switch
        $this->assertSame(5, $c['total']);
        $this->assertSame(['core-r1', 'core-r2', 'edge-gw-01'], $c['sample']);
        // Only the devices the scope lists by name, on either side; not the routers it matches by type.
        $this->assertEquals([(string) $gw->id => 'edge-gw-01', (string) $lab->id => 'lab-r1'], $c['device_names']);
    }

    public function test_a_fleet_wide_scope_matches_everything_and_names_nothing(): void
    {
        $this->actingAsUser();
        Device::factory()->count(3)->create();
        AlertPolicy::factory()->create(['name' => 'All', 'scope' => null]);

        $res = $this->getJson('/api/alert-policies')->assertOk();
        $c = collect($res->json('data'))->firstWhere('name', 'All')['coverage'];
        $this->assertSame(3, $c['matched']);
        $this->assertSame(3, $c['total']);
        $this->assertCount(3, $c['sample']);
        // An object, not [], so the page can always look names up by id.
        $this->assertStringContainsString('"device_names":{}', $res->getContent());
    }

    public function test_an_old_device_list_scope_names_its_devices_and_skips_deleted_ones(): void
    {
        $this->actingAsUser();
        $a = Device::factory()->create(['name' => 'lab-sw-1']);
        $gone = Device::factory()->create(['name' => 'old-box']);
        AlertPolicy::factory()->create(['name' => 'Lab', 'scope' => ['type' => 'devices', 'device_ids' => [$a->id, $gone->id]]]);
        $gone->delete();

        $c = $this->listed('Lab')['coverage'];
        $this->assertEquals([(string) $a->id => 'lab-sw-1'], $c['device_names']);
        $this->assertSame(['lab-sw-1'], $c['sample']);
    }

    public function test_a_scope_that_matches_nothing_says_so(): void
    {
        $this->actingAsUser();
        Device::factory()->create(['device_type' => 'router']);
        AlertPolicy::factory()->create(['name' => 'Servers', 'scope' => ['include' => ['device_types' => ['server']]]]);

        $c = $this->listed('Servers')['coverage'];
        $this->assertSame(0, $c['matched']);
        $this->assertSame([], $c['sample']);
    }

    public function test_saving_a_policy_does_not_return_coverage(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/alert-policies', ['name' => 'Down', 'condition' => 'device_down'])
            ->assertCreated()->assertJsonMissingPath('data.coverage');
    }
}
