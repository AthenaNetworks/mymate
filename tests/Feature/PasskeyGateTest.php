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

    private function withPasskey(User $user): void
    {
        $user->passkeys()->create([
            'name' => 'Victim key',
            // A valid base64 (no padding) id - the verify-options action base64-decodes it.
            'credential_id' => rtrim(base64_encode('victim-credential'), '='),
            'credential' => ['type' => 'public-key'],
        ]);
    }

    // --- GitHub #42: a password-only session must not be able to satisfy the gate itself ---------

    public function test_a_password_only_session_cannot_self_enrol_a_passkey_when_one_exists(): void
    {
        // The bypass: an attacker with the victim's password logs in (unverified) and tries to mint
        // their OWN authenticator to get a verified session. The victim already has a passkey, so
        // both register + register-options must be gated - they can only VERIFY the existing one.
        $user = User::factory()->admin()->create();
        $this->withPasskey($user);

        $this->actingAs($user)->postJson('/api/passkeys/register')->assertStatus(423);
        $this->actingAs($user)->postJson('/api/passkeys/register/options')->assertStatus(423);
    }

    public function test_a_password_only_session_cannot_delete_a_passkey(): void
    {
        // The second half of the attack: deleting the victim's credential to lock them out.
        $user = User::factory()->admin()->create();
        $this->withPasskey($user);
        $passkey = $user->passkeys()->first();

        $this->actingAs($user)->deleteJson("/api/passkeys/{$passkey->id}")->assertStatus(423);
    }

    public function test_the_verify_ceremony_stays_reachable_for_a_passkey_holder(): void
    {
        // The legitimate way to become verified must NOT be gated, or a 2FA user could never log in.
        $user = User::factory()->admin()->create();
        $this->withPasskey($user);

        // The gate must let the verification ceremony through - a 423 would strand a 2FA user with
        // no way to become verified. The options controller itself needs a real Sanctum session
        // (WebAuthn is browser-only, per this file's note), which the /api test harness doesn't
        // bind - so we assert only that the gate did NOT block it (anything but 423).
        $status = $this->actingAs($user)->postJson('/api/passkeys/verify/options')->getStatusCode();
        $this->assertNotSame(423, $status, 'the verify ceremony must not be gated');
    }

    public function test_first_enrolment_stays_reachable_when_required_without_a_passkey(): void
    {
        // A mandatory-enrol user with no passkey yet must still reach register/options to enrol.
        $this->requirePasskeys();
        $status = $this->actingAs(User::factory()->admin()->create())
            ->postJson('/api/passkeys/register/options')->getStatusCode();
        $this->assertNotSame(423, $status, 'first enrolment must not be gated');
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
