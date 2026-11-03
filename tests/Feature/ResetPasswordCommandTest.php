<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ResetPasswordCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sets_a_new_password_and_signs_out_remembered_logins(): void
    {
        $user = User::factory()->create(['email' => 'boss@example.com', 'password' => 'OldPassword123']);
        $user->forceFill(['remember_token' => 'old-token'])->save();

        $this->artisan('mymate:user:password', ['email' => 'boss@example.com'])
            ->expectsQuestion('New password', 'NewPassword456')
            ->expectsQuestion('Confirm new password', 'NewPassword456')
            ->expectsOutputToContain('Password updated for boss@example.com')
            ->assertSuccessful();

        $user->refresh();
        $this->assertTrue(Hash::check('NewPassword456', $user->password));
        $this->assertNotSame('old-token', $user->remember_token);

        $this->postJson('/login', ['email' => 'boss@example.com', 'password' => 'NewPassword456'])
            ->assertSuccessful();
    }

    public function test_it_rejects_a_weak_or_mismatched_password_and_keeps_the_old_one(): void
    {
        $user = User::factory()->create(['email' => 'boss@example.com', 'password' => 'OldPassword123']);

        $this->artisan('mymate:user:password', ['email' => 'boss@example.com'])
            ->expectsQuestion('New password', 'short')
            ->expectsQuestion('Confirm new password', 'short')
            ->assertFailed();

        $this->artisan('mymate:user:password', ['email' => 'boss@example.com'])
            ->expectsQuestion('New password', 'NewPassword456')
            ->expectsQuestion('Confirm new password', 'NewPassword457')
            ->assertFailed();

        $this->assertTrue(Hash::check('OldPassword123', $user->fresh()->password));
    }

    public function test_it_fails_for_an_unknown_operator(): void
    {
        $this->artisan('mymate:user:password', ['email' => 'nobody@example.com'])
            ->expectsOutputToContain('No operator found')
            ->assertFailed();
    }
}
