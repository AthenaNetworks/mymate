<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_api_requests_are_rejected(): void
    {
        $this->getJson('/api/devices')->assertUnauthorized();      // 401
        $this->getJson('/api/links')->assertUnauthorized();
        $this->postJson('/api/devices', [])->assertUnauthorized();
    }

    public function test_health_endpoint_stays_public(): void
    {
        // Load-balancer probe must work without credentials.
        $this->getJson('/api/health')->assertOk();
    }

    public function test_authenticated_operator_can_reach_the_api(): void
    {
        $this->actingAs(User::factory()->create());

        $this->getJson('/api/devices')->assertOk();
        $this->getJson('/api/user')->assertOk();
    }

    public function test_login_succeeds_with_valid_credentials(): void
    {
        $user = User::factory()->create(['email' => 'op@example.com', 'password' => 'correct-horse-battery']);

        $this->postJson('/login', ['email' => 'op@example.com', 'password' => 'correct-horse-battery'])
            ->assertOk()
            ->assertJsonPath('email', 'op@example.com')
            ->assertJsonPath('id', $user->id);

        $this->assertAuthenticatedAs($user); // session now carries the operator
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->create(['email' => 'op@example.com', 'password' => 'correct-horse-battery']);

        $this->postJson('/login', ['email' => 'op@example.com', 'password' => 'wrong'])
            ->assertStatus(422)->assertJsonValidationErrors(['email']);

        $this->assertGuest();
        $this->getJson('/api/devices')->assertUnauthorized(); // still locked out
    }

    public function test_login_validation(): void
    {
        $this->postJson('/login', [])
            ->assertStatus(422)->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_logout_ends_the_session(): void
    {
        $this->actingAs(User::factory()->create());

        $this->postJson('/logout')->assertNoContent();
        $this->assertGuest();
    }

    public function test_operator_can_change_their_own_password(): void
    {
        $user = User::factory()->create(['email' => 'op@example.com', 'password' => 'old-password-123']);
        $this->actingAs($user);

        $this->putJson('/api/account/password', [
            'current_password' => 'old-password-123',
            'password' => 'NewPassw0rd',
            'password_confirmation' => 'NewPassw0rd',
        ])->assertNoContent();

        // The old password no longer works; the new one does.
        $this->postJson('/login', ['email' => 'op@example.com', 'password' => 'old-password-123'])->assertStatus(422);
        $this->postJson('/login', ['email' => 'op@example.com', 'password' => 'NewPassw0rd'])->assertOk();
    }

    public function test_password_change_rejects_the_wrong_current_password(): void
    {
        $user = User::factory()->create(['password' => 'old-password-123']);
        $this->actingAs($user);

        $this->putJson('/api/account/password', [
            'current_password' => 'not-the-right-one',
            'password' => 'NewPassw0rd',
            'password_confirmation' => 'NewPassw0rd',
        ])->assertStatus(422)->assertJsonValidationErrors(['current_password']);

        $this->assertTrue(Hash::check('old-password-123', $user->fresh()->password));
    }

    public function test_password_change_rejects_an_unconfirmed_or_weak_password(): void
    {
        $user = User::factory()->create(['password' => 'old-password-123']);
        $this->actingAs($user);

        $this->putJson('/api/account/password', [
            'current_password' => 'old-password-123',
            'password' => 'NewPassw0rd',
            'password_confirmation' => 'does-not-match',
        ])->assertStatus(422)->assertJsonValidationErrors(['password']);

        $this->putJson('/api/account/password', [
            'current_password' => 'old-password-123',
            'password' => 'weak',
            'password_confirmation' => 'weak',
        ])->assertStatus(422)->assertJsonValidationErrors(['password']);
    }

    public function test_password_change_requires_authentication(): void
    {
        $this->putJson('/api/account/password', [
            'current_password' => 'whatever',
            'password' => 'NewPassw0rd',
            'password_confirmation' => 'NewPassw0rd',
        ])->assertUnauthorized();
    }

    /**
     * Regression: live traffic hit this exact bug - the daily-rotated `mymate` log file is
     * written by both root-run Horizon/loop workers and the php-fpm web user, and whichever
     * creates a given day's file first sets its owner/permissions. When the web user
     * couldn't write to it, `EngineLog::warning('auth: password changed', ...)` - called right
     * after the password was already saved - threw, turning a successful change into a 500.
     * The password had genuinely changed; only the response (and the UI, which then showed
     * an error) was wrong. Force the same class of failure (an unusable log path) here and
     * confirm the request still succeeds.
     */
    public function test_a_broken_log_channel_does_not_fail_the_password_change(): void
    {
        $user = User::factory()->create(['password' => 'old-password-123']);
        $this->actingAs($user);

        // An existing file used as a path *component* makes any nested path unusable
        // regardless of OS permissions - reproduces "could not be opened in append mode"
        // without depending on the test runner's own user/permissions (it may run as root,
        // which bypasses normal permission checks entirely).
        $unusablePath = storage_path('logs/mymate-2026-07-01.log').'/not/a/real/dir.log';
        config(['logging.channels.mymate.path' => $unusablePath]);

        $this->putJson('/api/account/password', [
            'current_password' => 'old-password-123',
            'password' => 'NewPassw0rd',
            'password_confirmation' => 'NewPassw0rd',
        ])->assertNoContent();

        $this->assertTrue(Hash::check('NewPassw0rd', $user->fresh()->password));
    }
}
