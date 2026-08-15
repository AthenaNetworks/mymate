<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\AuthSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The passkey enforcement gate (EnsurePasskeyVerified) + the me() stage flags. The WebAuthn
 * ceremony itself needs a real browser, so these fake the "verified" session flag and focus on
 * the policy: who is gated, who isn't (API keys, exempt accounts), and what the SPA is told to do.
 */
class PasskeyGateTest extends TestCase
{
    use RefreshDatabase;

    private function requirePasskeys(): void
    {
        app(AuthSettings::class)->setPasskeyRequired(true);
    }

    public function test_a_session_login_without_a_passkey_is_gated_when_required(): void
    {
        $this->requirePasskeys();
        $this->actingAs(User::factory()->admin()->create())
            ->getJson('/api/devices')
            ->assertStatus(423)
            ->assertJsonPath('code', 'passkey_required');
    }

    public function test_an_operator_with_a_passkey_is_gated_for_the_2fa_step(): void
    {
        // No fleet-wide requirement, but this operator has registered a passkey -> opt-in 2FA,
        // so they're gated until they tap it this session. (The "verified" unlock needs a real
        // WebAuthn ceremony + session, exercised in manual HTTPS testing.)
        $user = User::factory()->admin()->create();
        $user->passkeys()->create([
            'name' => 'Yubikey',
            'credential_id' => 'test-credential-1',
            'credential' => ['type' => 'public-key'], // opaque to the gate; it only checks existence
        ]);

        $this->actingAs($user)->getJson('/api/devices')->assertStatus(423);
    }

    public function test_an_exempt_operator_is_never_gated(): void
    {
        $this->requirePasskeys();
        $user = User::factory()->admin()->create();
        $user->forceFill(['passkey_exempt' => true])->save(); // privilege, not mass-assignable

        $this->actingAs($user)->getJson('/api/devices')->assertOk();
    }

    public function test_an_api_key_is_never_gated_even_when_required(): void
    {
        $this->requirePasskeys();
        $user = User::factory()->admin()->create();
        $token = $user->createToken('script')->plainTextToken;

        // A bearer token carries a currentAccessToken, so the gate skips it - scripts keep working.
        $this->withToken($token)->getJson('/api/devices')->assertOk();
    }

    public function test_with_no_requirement_and_no_passkey_it_passes(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->getJson('/api/devices')
            ->assertOk();
    }

    public function test_only_an_admin_can_flip_the_requirement(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->putJson('/api/settings/security', ['passkey_required' => true])
            ->assertForbidden();

        $this->actingAs(User::factory()->admin()->create())
            ->putJson('/api/settings/security', ['passkey_required' => true])
            ->assertOk()
            ->assertJsonPath('data.passkey_required', true);
    }

    public function test_me_reports_the_enrol_stage_when_required_without_a_passkey(): void
    {
        $this->requirePasskeys();
        // /user is allowlisted so it answers even for a not-yet-verified operator.
        $this->actingAs(User::factory()->admin()->create())
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('passkey_required', true)
            ->assertJsonPath('has_passkey', false)
            ->assertJsonPath('passkey_stage', 'enrol');
    }

    public function test_security_settings_shows_the_affected_operator_count(): void
    {
        User::factory()->admin()->create();          // no passkey -> affected
        $exempt = User::factory()->create(['is_admin' => false]);
        $exempt->forceFill(['passkey_exempt' => true])->save(); // not affected

        $this->actingAs(User::factory()->admin()->create()) // + this admin = 2 affected
            ->getJson('/api/settings/security')
            ->assertOk()
            ->assertJsonPath('data.affected_operators', 2);
    }
}
