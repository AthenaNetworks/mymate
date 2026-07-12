<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Link;
use App\Models\NetworkInterface;
use App\Models\Subnet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * App-wide read-only tier. A non-admin operator can view everything but change
 * nothing - every write endpoint is gated by `RestrictWritesToAdmins`. Admins are unaffected.
 */
class ViewerReadOnlyTest extends TestCase
{
    use RefreshDatabase;

    private function link(): Link
    {
        $a = Device::factory()->create();
        $b = Device::factory()->create();
        $aIf = NetworkInterface::factory()->for($a)->create();
        $bIf = NetworkInterface::factory()->for($b)->create();

        return Link::create([
            'a_device_id' => $a->id, 'a_interface_id' => $aIf->id,
            'b_device_id' => $b->id, 'b_interface_id' => $bIf->id,
        ]);
    }

    // --- Reads stay open --------------------------------------------------

    public function test_non_admin_can_read_devices_and_links(): void
    {
        $this->actingAs(User::factory()->create());

        $this->getJson('/api/devices')->assertOk();
        $this->getJson('/api/links')->assertOk();
    }

    // --- Writes are refused for non-admins --------------------------------

    public function test_non_admin_cannot_delete_a_device(): void
    {
        $device = Device::factory()->create();
        $this->actingAs(User::factory()->create());

        $this->deleteJson("/api/devices/{$device->id}")->assertForbidden();
        $this->assertDatabaseHas('devices', ['id' => $device->id]);
    }

    public function test_non_admin_cannot_create_or_edit_a_device(): void
    {
        $device = Device::factory()->create();
        $this->actingAs(User::factory()->create());

        $this->postJson('/api/devices', ['name' => 'X', 'mgmt_ip' => '10.9.9.9', 'poll_method' => 'none'])->assertForbidden();
        $this->putJson("/api/devices/{$device->id}", ['name' => 'Renamed'])->assertForbidden();
        $this->patchJson("/api/devices/{$device->id}/position", ['map_x' => 1, 'map_y' => 2])->assertForbidden();
    }

    public function test_non_admin_cannot_delete_a_link(): void
    {
        $link = $this->link();
        $this->actingAs(User::factory()->create());

        $this->deleteJson("/api/links/{$link->id}")->assertForbidden();
        $this->assertDatabaseHas('links', ['id' => $link->id]);
    }

    public function test_non_admin_cannot_run_scans_or_upgrades(): void
    {
        $subnet = Subnet::factory()->create();
        $this->actingAs(User::factory()->create());

        $this->postJson("/api/subnets/{$subnet->id}/scan")->assertForbidden();
        $this->postJson('/api/devices/upgrade', ['device_ids' => [1]])->assertForbidden();
    }

    // --- Self-service exception + admin bypass ----------------------------

    public function test_non_admin_can_still_change_their_own_password(): void
    {
        $user = User::factory()->create(['password' => 'old-password-123']);
        $this->actingAs($user);

        $this->putJson('/api/account/password', [
            'current_password' => 'old-password-123',
            'password' => 'NewPassw0rd',
            'password_confirmation' => 'NewPassw0rd',
        ])->assertNoContent();
    }

    public function test_admin_can_still_delete_a_device_and_link(): void
    {
        $device = Device::factory()->create();
        $link = $this->link();
        $this->actingAs(User::factory()->admin()->create());

        $this->deleteJson("/api/devices/{$device->id}")->assertNoContent();
        $this->deleteJson("/api/links/{$link->id}")->assertNoContent();

        $this->assertDatabaseMissing('devices', ['id' => $device->id]);
        $this->assertDatabaseMissing('links', ['id' => $link->id]);
    }
}
