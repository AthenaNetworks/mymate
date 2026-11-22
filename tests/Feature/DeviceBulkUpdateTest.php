<?php

namespace Tests\Feature;

use App\Enums\DeviceType;
use App\Enums\PollMethod;
use App\Models\Agent;
use App\Models\Credential;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceBulkUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsUser();
    }

    /** @param  array<string, mixed>  $changes */
    private function bulk(array $ids, array $changes): \Illuminate\Testing\TestResponse
    {
        return $this->patchJson('/api/devices/bulk', ['device_ids' => $ids, 'changes' => $changes]);
    }

    public function test_it_applies_the_changes_to_every_selected_device_and_nothing_else(): void
    {
        $a = Device::factory()->create(['device_type' => DeviceType::Unknown, 'monitored' => true]);
        $b = Device::factory()->create(['device_type' => DeviceType::Unknown, 'monitored' => true]);
        $other = Device::factory()->create(['device_type' => DeviceType::Unknown, 'monitored' => true]);

        $this->bulk([$a->id, $b->id], ['device_type' => 'switch', 'monitored' => false, 'icon' => 'server', 'icon_color' => '#ff0000'])
            ->assertOk()
            ->assertJsonPath('data.updated', 2);

        foreach ([$a, $b] as $d) {
            $this->assertDatabaseHas('devices', ['id' => $d->id, 'device_type' => 'switch', 'monitored' => false, 'icon' => 'server', 'icon_color' => '#ff0000']);
        }
        $this->assertDatabaseHas('devices', ['id' => $other->id, 'device_type' => 'unknown', 'monitored' => true, 'icon' => null]);
    }

    public function test_only_the_fields_sent_are_changed(): void
    {
        $d = Device::factory()->create(['name' => 'Keep', 'device_type' => DeviceType::Router, 'icon' => 'switch']);

        $this->bulk([$d->id], ['monitored' => false])->assertOk();

        $this->assertDatabaseHas('devices', ['id' => $d->id, 'name' => 'Keep', 'device_type' => 'router', 'icon' => 'switch', 'monitored' => false]);
    }

    public function test_setting_a_location_puts_every_device_on_that_point_as_a_manual_pin(): void
    {
        $a = Device::factory()->create();
        $b = Device::factory()->create(['latitude' => 1, 'longitude' => 2, 'geo_source' => 'snmp']);

        $this->bulk([$a->id, $b->id], ['latitude' => -33.8688, 'longitude' => 151.2093])->assertOk();

        foreach ([$a, $b] as $d) {
            $d->refresh();
            $this->assertEqualsWithDelta(-33.8688, (float) $d->latitude, 0.000001);
            $this->assertEqualsWithDelta(151.2093, (float) $d->longitude, 0.000001);
            $this->assertSame('manual', $d->geo_source);
        }
    }

    public function test_clearing_the_location_clears_it_everywhere(): void
    {
        $a = Device::factory()->create(['latitude' => 1, 'longitude' => 2, 'geo_source' => 'manual']);

        $this->bulk([$a->id], ['latitude' => null, 'longitude' => null])->assertOk();

        $this->assertDatabaseHas('devices', ['id' => $a->id, 'latitude' => null, 'longitude' => null, 'geo_source' => null]);
    }

    public function test_a_latitude_needs_a_longitude(): void
    {
        $a = Device::factory()->create();

        $this->bulk([$a->id], ['latitude' => 10])->assertUnprocessable()->assertJsonValidationErrors('changes.longitude');
    }

    public function test_the_single_device_edit_also_needs_both_coordinates(): void
    {
        $a = Device::factory()->create();

        $this->patchJson("/api/devices/{$a->id}", ['latitude' => 10])->assertUnprocessable()->assertJsonValidationErrors('longitude');
        $this->patchJson("/api/devices/{$a->id}", ['name' => 'Still fine without either'])->assertOk();
    }

    public function test_fields_that_are_per_device_cannot_be_changed_in_bulk(): void
    {
        $a = Device::factory()->create(['name' => 'Keep']);

        $this->bulk([$a->id], ['name' => 'Same for all'])->assertUnprocessable()->assertJsonValidationErrors('changes');
        $this->bulk([$a->id], ['mgmt_ip' => '10.9.9.9'])->assertUnprocessable()->assertJsonValidationErrors('changes');
        $this->assertDatabaseHas('devices', ['id' => $a->id, 'name' => 'Keep']);
    }

    public function test_it_needs_at_least_one_change_and_one_device(): void
    {
        $a = Device::factory()->create();

        $this->bulk([$a->id], [])->assertUnprocessable()->assertJsonValidationErrors('changes');
        $this->bulk([], ['monitored' => false])->assertUnprocessable()->assertJsonValidationErrors('device_ids');
    }

    public function test_a_device_that_has_gone_rejects_the_whole_batch(): void
    {
        $a = Device::factory()->create(['monitored' => true]);

        $this->bulk([$a->id, 999999], ['monitored' => false])->assertUnprocessable()->assertJsonValidationErrors('device_ids');
        $this->assertDatabaseHas('devices', ['id' => $a->id, 'monitored' => true]);
    }

    public function test_one_device_failing_a_check_changes_nothing_and_names_the_device(): void
    {
        $ok = Device::factory()->create(['name' => 'HasIp', 'mgmt_ip' => '10.0.0.1', 'poll_method' => PollMethod::None]);
        $static = Device::factory()->create(['name' => 'NoIp', 'mgmt_ip' => null, 'poll_method' => PollMethod::None]);
        $cred = Credential::factory()->create(); // snmp

        $res = $this->bulk([$ok->id, $static->id], ['poll_method' => 'snmp', 'credential_id' => $cred->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors("devices.{$static->id}")
            ->assertJsonMissingValidationErrors("devices.{$ok->id}");
        $this->assertStringContainsString('NoIp', $res->json('errors')["devices.{$static->id}"][0]);

        $this->assertDatabaseHas('devices', ['id' => $ok->id, 'poll_method' => 'none', 'credential_id' => null]);
    }

    public function test_a_new_poll_method_needs_its_credential_chosen_too(): void
    {
        $a = Device::factory()->create(['mgmt_ip' => '10.0.0.1', 'poll_method' => PollMethod::None]);

        $this->bulk([$a->id], ['poll_method' => 'snmp'])->assertUnprocessable()->assertJsonValidationErrors('changes.credential_id');
    }

    public function test_the_poll_credential_has_to_match_the_poll_method(): void
    {
        $a = Device::factory()->create(['mgmt_ip' => '10.0.0.1', 'poll_method' => PollMethod::None]);
        $ros = Credential::factory()->routeros()->create();

        $this->bulk([$a->id], ['poll_method' => 'snmp', 'credential_id' => $ros->id])
            ->assertUnprocessable()->assertJsonValidationErrors('changes.credential_id');
    }

    public function test_a_credential_alone_has_to_suit_every_selected_device(): void
    {
        $snmp = Device::factory()->create(['mgmt_ip' => '10.0.0.1', 'poll_method' => PollMethod::Snmp]);
        $ros = Device::factory()->create(['mgmt_ip' => '10.0.0.2', 'poll_method' => PollMethod::RouterOs]);
        $cred = Credential::factory()->create(); // snmp

        $this->bulk([$snmp->id, $ros->id], ['credential_id' => $cred->id])
            ->assertUnprocessable()->assertJsonValidationErrors('changes.credential_id');

        $this->bulk([$snmp->id], ['credential_id' => $cred->id])->assertOk();
        $this->assertDatabaseHas('devices', ['id' => $snmp->id, 'credential_id' => $cred->id]);
    }

    public function test_switching_to_ping_only_drops_the_poll_credential(): void
    {
        $cred = Credential::factory()->create();
        $a = Device::factory()->create(['mgmt_ip' => '10.0.0.1', 'poll_method' => PollMethod::Snmp, 'credential_id' => $cred->id]);

        $this->bulk([$a->id], ['poll_method' => 'none'])->assertOk();

        $this->assertDatabaseHas('devices', ['id' => $a->id, 'poll_method' => 'none', 'credential_id' => null]);
    }

    public function test_backup_and_ospf_credentials_have_to_be_the_right_kind(): void
    {
        $a = Device::factory()->create();
        $snmp = Credential::factory()->create();
        $ssh = Credential::factory()->ssh()->create();
        $ros = Credential::factory()->routeros()->create();

        $this->bulk([$a->id], ['ssh_credential_id' => $snmp->id])->assertUnprocessable()->assertJsonValidationErrors('changes.ssh_credential_id');
        $this->bulk([$a->id], ['routeros_credential_id' => $snmp->id])->assertUnprocessable()->assertJsonValidationErrors('changes.routeros_credential_id');

        $this->bulk([$a->id], ['ssh_credential_id' => $ssh->id, 'routeros_credential_id' => $ros->id])->assertOk();
        $this->assertDatabaseHas('devices', ['id' => $a->id, 'ssh_credential_id' => $ssh->id, 'routeros_credential_id' => $ros->id]);
    }

    public function test_moving_onto_an_agent_that_already_polls_the_ip_is_refused(): void
    {
        $agent = Agent::factory()->create();
        Device::factory()->create(['name' => 'Already', 'mgmt_ip' => '10.0.0.5', 'agent_id' => $agent->id]);
        $moving = Device::factory()->create(['mgmt_ip' => '10.0.0.5', 'agent_id' => null]);

        $res = $this->bulk([$moving->id], ['agent_id' => $agent->id])
            ->assertUnprocessable()->assertJsonValidationErrors("devices.{$moving->id}");
        $this->assertStringContainsString('Already', $res->json('errors')["devices.{$moving->id}"][0]);
        $this->assertDatabaseHas('devices', ['id' => $moving->id, 'agent_id' => null]);
    }

    public function test_two_selected_devices_sharing_an_ip_cannot_both_move_onto_one_agent(): void
    {
        $a1 = Agent::factory()->create();
        $a2 = Agent::factory()->create();
        $x = Device::factory()->create(['mgmt_ip' => '10.0.0.7', 'agent_id' => $a1->id]);
        $y = Device::factory()->create(['mgmt_ip' => '10.0.0.7', 'agent_id' => null]);

        $this->bulk([$x->id, $y->id], ['agent_id' => $a2->id])
            ->assertUnprocessable()->assertJsonValidationErrors(["devices.{$x->id}", "devices.{$y->id}"]);
    }

    public function test_moving_devices_onto_an_agent(): void
    {
        $agent = Agent::factory()->create();
        $a = Device::factory()->create(['mgmt_ip' => '10.0.0.8']);
        $b = Device::factory()->create(['mgmt_ip' => '10.0.0.9']);

        $this->bulk([$a->id, $b->id], ['agent_id' => $agent->id])->assertOk();

        $this->assertSame(2, Device::where('agent_id', $agent->id)->count());
    }

    public function test_it_sets_a_shared_parent(): void
    {
        $parent = Device::factory()->create();
        $a = Device::factory()->create();
        $b = Device::factory()->create();

        $this->bulk([$a->id, $b->id], ['parent_device_id' => $parent->id])->assertOk();

        $this->assertSame(2, Device::where('parent_device_id', $parent->id)->count());
    }

    public function test_the_shared_parent_cannot_be_a_selected_device_or_below_one(): void
    {
        $top = Device::factory()->create();
        $below = Device::factory()->create(['parent_device_id' => $top->id]);
        $other = Device::factory()->create();

        // $below is under $top, so $top can't go under $below.
        $this->bulk([$top->id, $other->id], ['parent_device_id' => $below->id])
            ->assertUnprocessable()->assertJsonValidationErrors("devices.{$top->id}")
            ->assertJsonMissingValidationErrors("devices.{$other->id}");
        // A selected device can't be its own parent.
        $this->bulk([$top->id, $other->id], ['parent_device_id' => $other->id])
            ->assertUnprocessable()->assertJsonValidationErrors("devices.{$other->id}");

        $this->assertDatabaseHas('devices', ['id' => $top->id, 'parent_device_id' => null]);
        $this->assertDatabaseHas('devices', ['id' => $other->id, 'parent_device_id' => null]);
    }

    public function test_an_unknown_image_icon_is_refused(): void
    {
        $a = Device::factory()->create();

        $this->bulk([$a->id], ['icon' => 'img:999999'])->assertUnprocessable()->assertJsonValidationErrors('changes.icon');
    }

    public function test_a_read_only_operator_cannot_bulk_edit(): void
    {
        $a = Device::factory()->create(['monitored' => true]);
        $this->actingAs(User::factory()->create());

        $this->bulk([$a->id], ['monitored' => false])->assertForbidden();
        $this->assertDatabaseHas('devices', ['id' => $a->id, 'monitored' => true]);
    }

    public function test_the_single_device_edit_still_refuses_a_parent_loop(): void
    {
        $top = Device::factory()->create();
        $below = Device::factory()->create(['parent_device_id' => $top->id]);

        $this->patchJson("/api/devices/{$top->id}", ['parent_device_id' => $below->id])
            ->assertUnprocessable()->assertJsonValidationErrors('parent_device_id');
    }
}
