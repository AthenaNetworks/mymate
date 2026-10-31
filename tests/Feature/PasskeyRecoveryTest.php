<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\PasskeyRecovery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Getting back in after losing a passkey: one-time recovery codes (self-service), a reset by an
 * admin, and `mymate:user:reset-passkeys` for a sole admin on the server. None of them may let a
 * password-only session through without one of those.
 */
class PasskeyRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Look like the SPA, so Sanctum gives the request a session (the passkey flag lives there).
        $this->withHeaders(['Referer' => 'http://localhost/']);
    }

    private function withPasskey(User $user): User
    {
        $user->passkeys()->create([
            'name' => 'Laptop',
            'credential_id' => rtrim(base64_encode('cred-'.$user->id), '='),
            'credential' => ['type' => 'public-key'],
        ]);

        return $user;
    }

    public function test_a_verified_operator_gets_one_time_codes_shown_once(): void
    {
        $user = $this->withPasskey(User::factory()->create());

        $codes = $this->actingAs($user)->withSession(['passkey_verified' => true])
            ->postJson('/api/passkeys/recovery-codes')->assertOk()->json('codes');

        $this->assertCount(PasskeyRecovery::CODE_COUNT, $codes);
        $this->assertMatchesRegularExpression('/^[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4}$/', $codes[0]);
        $stored = $user->fresh()->passkey_recovery_codes;
        $this->assertNotContains($codes[0], $stored, 'only hashes are stored');
        $this->assertArrayNotHasKey('passkey_recovery_codes', $user->fresh()->toArray());
        $this->getJson('/api/user')->assertJsonPath('passkey_recovery_codes_left', PasskeyRecovery::CODE_COUNT);
    }

    public function test_a_password_only_session_cant_mint_codes(): void
    {
        $user = $this->withPasskey(User::factory()->create());

        // Not verified this session: an attacker with just the password mustn't get codes.
        $this->actingAs($user)->postJson('/api/passkeys/recovery-codes')->assertStatus(423);
        $this->assertNull($user->fresh()->passkey_recovery_codes);
    }

    public function test_a_recovery_code_gets_past_the_gate_once(): void
    {
        $user = $this->withPasskey(User::factory()->admin()->create());
        $codes = app(PasskeyRecovery::class)->generate($user);

        $this->actingAs($user)->getJson('/api/devices')->assertStatus(423);
        $this->getJson('/api/user')->assertJsonPath('passkey_stage', 'challenge');

        $this->postJson('/api/passkeys/recover', ['code' => 'nope-nope-nope'])->assertUnprocessable();
        // Case and dashes don't matter when typing it in.
        $this->postJson('/api/passkeys/recover', ['code' => strtoupper(str_replace('-', ' ', $codes[0]))])
            ->assertOk()->assertJsonPath('recovery_codes_left', PasskeyRecovery::CODE_COUNT - 1);

        $this->getJson('/api/devices')->assertOk();
        $this->getJson('/api/user')->assertJsonPath('passkey_stage', 'verified');

        // Used up: a new session can't use it again.
        $this->flushSession();
        $this->postJson('/api/passkeys/recover', ['code' => $codes[0]])->assertUnprocessable();
    }

    public function test_recovery_attempts_are_rate_limited(): void
    {
        $user = $this->withPasskey(User::factory()->create());
        app(PasskeyRecovery::class)->generate($user);
        $this->actingAs($user);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/passkeys/recover', ['code' => 'aaaa-bbbb-cccc'])->assertUnprocessable();
        }
        $this->postJson('/api/passkeys/recover', ['code' => 'aaaa-bbbb-cccc'])->assertTooManyRequests();
    }

    public function test_an_admin_can_reset_another_operators_passkeys(): void
    {
        $lost = $this->withPasskey(User::factory()->admin()->create());
        app(PasskeyRecovery::class)->generate($lost);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->postJson("/api/users/{$lost->id}/passkeys/reset")
            ->assertOk()->assertJsonPath('removed', 1);

        $this->assertSame(0, $lost->passkeys()->count());
        $this->assertNull($lost->fresh()->passkey_recovery_codes);
        // Their next password login goes straight in (passkeys aren't required here).
        $this->flushSession();
        $this->actingAs($lost->fresh())->getJson('/api/devices')->assertOk();
    }

    public function test_only_admins_can_reset_and_a_locked_out_admin_cant_do_it_unverified(): void
    {
        $target = $this->withPasskey(User::factory()->create());

        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->postJson("/api/users/{$target->id}/passkeys/reset")->assertForbidden();

        // An admin with their own passkey still has to pass the gate first.
        $admin = $this->withPasskey(User::factory()->admin()->create());
        $this->flushSession();
        $this->actingAs($admin)->postJson("/api/users/{$target->id}/passkeys/reset")->assertStatus(423);
        $this->assertSame(1, $target->passkeys()->count());
    }

    public function test_the_command_resets_a_sole_admin(): void
    {
        $admin = $this->withPasskey(User::factory()->admin()->create(['email' => 'boss@example.com']));
        app(PasskeyRecovery::class)->generate($admin);

        $this->artisan('mymate:user:reset-passkeys', ['email' => 'boss@example.com'])
            ->expectsOutputToContain('Laptop')
            ->expectsConfirmation('Remove them, and any recovery codes?', 'yes')
            ->assertSuccessful();

        $this->assertSame(0, $admin->passkeys()->count());
        $this->assertNull($admin->fresh()->passkey_recovery_codes);
        $this->artisan('mymate:user:reset-passkeys', ['email' => 'nobody@example.com'])->assertFailed();
    }
}
