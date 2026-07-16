<?php

namespace Tests\Feature;

use App\Actions\System\FactoryReset;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FactoryResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_wipes_monitoring_data_and_operators_but_keeps_admins(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $operator = User::factory()->create(['is_admin' => false]);
        Device::factory()->count(3)->create();

        (new FactoryReset)();

        $this->assertDatabaseCount('devices', 0);
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
        $this->assertDatabaseMissing('users', ['id' => $operator->id]);

        // A usable default map must remain - the app places new devices on it and the map view
        // targets it, so a reset that left zero maps would strand the map page.
        $this->assertDatabaseHas('maps', ['is_default' => true]);
        $this->assertSame(1, \App\Models\Map::count());
    }

    public function test_endpoint_is_admin_only_and_password_confirmed(): void
    {
        // The User model 'hashed' cast hashes this on set - pass it plain, not pre-hashed.
        $admin = User::factory()->create(['is_admin' => true, 'password' => 'correct-horse']);
        $operator = User::factory()->create(['is_admin' => false]);
        Device::factory()->count(2)->create();

        // Non-admin can't reach it at all.
        $this->actingAs($operator)->postJson('/api/system/factory-reset', ['password' => 'whatever'])->assertForbidden();

        // Admin with the wrong password is rejected, data untouched.
        $this->actingAs($admin)->postJson('/api/system/factory-reset', ['password' => 'nope'])->assertStatus(422);
        $this->assertDatabaseCount('devices', 2);

        // Admin with the right password wipes the fleet.
        $this->actingAs($admin)->postJson('/api/system/factory-reset', ['password' => 'correct-horse'])->assertOk();
        $this->assertDatabaseCount('devices', 0);
    }
}
