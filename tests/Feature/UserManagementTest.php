<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * In-app operator management. Admins manage accounts; a normal operator may
 * only *view* the roster (and only a reduced payload). Passwords are never exposed and
 * `is_admin` can never be self-escalated.
 */
class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    // --- Read: tier-shaped roster ----------------------------------------

    public function test_any_operator_can_view_the_roster(): void
    {
        User::factory()->admin()->create(['name' => 'Boss']);
        $viewer = User::factory()->create();
        $this->actingAs($viewer);

        $this->getJson('/api/users')->assertOk()->assertJsonCount(2);
    }

    public function test_non_admin_roster_hides_email_and_timestamps(): void
    {
        User::factory()->admin()->create(['email' => 'boss@example.com']);
        $viewer = User::factory()->create();
        $this->actingAs($viewer);

        $response = $this->getJson('/api/users')->assertOk();

        // Every row exposes id/name/is_admin but never email, timestamps, or password.
        foreach ($response->json() as $row) {
            $this->assertArrayHasKey('name', $row);
            $this->assertArrayHasKey('is_admin', $row);
            $this->assertArrayNotHasKey('email', $row);
            $this->assertArrayNotHasKey('created_at', $row);
            $this->assertArrayNotHasKey('password', $row);
        }
    }

    public function test_admin_roster_includes_email(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $this->getJson('/api/users')->assertOk()->assertJsonFragment(['email' => $admin->email]);
    }

    // --- Write: admin gate ------------------------------------------------

    public function test_non_admin_cannot_create_update_or_delete(): void
    {
        $admin = User::factory()->admin()->create();
        $viewer = User::factory()->create();
        $this->actingAs($viewer);

        $this->postJson('/api/users', [
            'name' => 'Nope', 'email' => 'nope@example.com', 'password' => 'Password123',
        ])->assertForbidden();

        $this->putJson("/api/users/{$admin->id}", ['name' => 'Hacked'])->assertForbidden();
        $this->deleteJson("/api/users/{$admin->id}")->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'nope@example.com']);
        $this->assertDatabaseHas('users', ['id' => $admin->id, 'name' => $admin->name]);
    }

    public function test_admin_can_create_an_operator(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->postJson('/api/users', [
            'name' => 'New Op', 'email' => 'newop@example.com', 'password' => 'Password123',
        ])->assertCreated()->assertJsonFragment(['email' => 'newop@example.com', 'is_admin' => false]);

        $created = User::where('email', 'newop@example.com')->first();
        $this->assertNotNull($created);
        $this->assertFalse($created->is_admin);
        $this->assertTrue(Hash::check('Password123', $created->password));
    }

    public function test_admin_can_create_another_admin(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->postJson('/api/users', [
            'name' => 'Co Admin', 'email' => 'co@example.com', 'password' => 'Password123', 'is_admin' => true,
        ])->assertCreated()->assertJsonFragment(['is_admin' => true]);

        $this->assertTrue(User::where('email', 'co@example.com')->first()->is_admin);
    }

    public function test_create_enforces_the_password_floor_and_unique_email(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'taken@example.com']);
        $this->actingAs($admin);

        // Too weak (no uppercase/number).
        $this->postJson('/api/users', [
            'name' => 'Weak', 'email' => 'weak@example.com', 'password' => 'password',
        ])->assertStatus(422)->assertJsonValidationErrors(['password']);

        // Duplicate email.
        $this->postJson('/api/users', [
            'name' => 'Dup', 'email' => 'taken@example.com', 'password' => 'Password123',
        ])->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_admin_can_edit_a_name_and_toggle_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->admin()->create(['name' => 'Old']);
        $this->actingAs($admin);

        $this->putJson("/api/users/{$other->id}", ['name' => 'New', 'is_admin' => false])
            ->assertOk()->assertJsonFragment(['name' => 'New', 'is_admin' => false]);

        $this->assertDatabaseHas('users', ['id' => $other->id, 'name' => 'New', 'is_admin' => false]);
    }

    public function test_update_with_blank_password_keeps_the_current_one(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->create(['password' => 'KeepThis123']);
        $this->actingAs($admin);

        $this->putJson("/api/users/{$other->id}", ['name' => 'Renamed', 'password' => ''])->assertOk();

        $this->assertTrue(Hash::check('KeepThis123', $other->fresh()->password));
    }

    // --- Lockout guards ---------------------------------------------------

    public function test_cannot_delete_your_own_account(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->admin()->create(); // a second admin exists, so it's not the last-admin rule
        $this->actingAs($admin);

        $this->deleteJson("/api/users/{$admin->id}")->assertStatus(422)->assertJsonValidationErrors(['user']);
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_admin_can_delete_another_operator(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->create();
        $this->actingAs($admin);

        $this->deleteJson("/api/users/{$other->id}")->assertNoContent();
        $this->assertDatabaseMissing('users', ['id' => $other->id]);
    }

    public function test_cannot_demote_the_last_admin(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(); // a non-admin operator
        $this->actingAs($admin);

        $this->putJson("/api/users/{$admin->id}", ['is_admin' => false])
            ->assertStatus(422)->assertJsonValidationErrors(['is_admin']);

        $this->assertTrue($admin->fresh()->is_admin);
    }

    public function test_can_demote_an_admin_when_another_admin_remains(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->admin()->create();
        $this->actingAs($admin);

        $this->putJson("/api/users/{$other->id}", ['is_admin' => false])->assertOk();
        $this->assertFalse($other->fresh()->is_admin);
    }

    public function test_me_endpoint_exposes_the_admin_flag(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $this->getJson('/api/user')->assertOk()->assertJsonPath('is_admin', true);

        $this->actingAs(User::factory()->create());
        $this->getJson('/api/user')->assertOk()->assertJsonPath('is_admin', false);
    }

    public function test_users_routes_require_authentication(): void
    {
        $this->getJson('/api/users')->assertUnauthorized();
        $this->postJson('/api/users', [])->assertUnauthorized();
    }

    // --- CLI bootstrap ----------------------------------------------------

    public function test_first_created_operator_is_forced_admin(): void
    {
        $this->artisan('mymate:user:create', ['email' => 'first@example.com', 'name' => 'First'])
            ->expectsQuestion('Password', 'a-very-long-password')
            ->assertSuccessful();

        $this->assertTrue(User::where('email', 'first@example.com')->first()->is_admin);
    }

    public function test_subsequent_operator_is_not_admin_without_the_flag(): void
    {
        User::factory()->admin()->create();

        $this->artisan('mymate:user:create', ['email' => 'second@example.com', 'name' => 'Second'])
            ->expectsQuestion('Password', 'a-very-long-password')
            ->assertSuccessful();

        $this->assertFalse(User::where('email', 'second@example.com')->first()->is_admin);
    }
}
