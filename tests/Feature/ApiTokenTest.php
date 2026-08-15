<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_operator_can_mint_list_and_revoke_their_own_keys(): void
    {
        $user = User::factory()->create(['is_admin' => false]); // a read-only operator, still self-service
        $this->actingAs($user);

        $created = $this->postJson('/api/api-tokens', ['name' => 'my script'])
            ->assertCreated()
            ->assertJsonStructure(['id', 'name', 'token'])
            ->json();
        $this->assertNotEmpty($created['token']); // plaintext shown once

        $this->getJson('/api/api-tokens')->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.name', 'my script');

        $this->deleteJson("/api/api-tokens/{$created['id']}")->assertNoContent();
        $this->getJson('/api/api-tokens')->assertOk()->assertJsonCount(0);
    }

    public function test_a_key_cannot_revoke_another_operators_key(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $token = $owner->createToken('owned');

        $this->actingAs($other)
            ->deleteJson('/api/api-tokens/'.$token->accessToken->getKey())
            ->assertNoContent(); // succeeds, but scoped to $other's tokens - so nothing is deleted

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->accessToken->getKey()]);
    }

    public function test_a_read_only_operators_key_stays_read_only(): void
    {
        $viewer = User::factory()->create(['is_admin' => false]);
        $key = $viewer->createToken('viewer')->plainTextToken;

        $this->withToken($key)->getJson('/api/devices')->assertOk();
        $this->withToken($key)->postJson('/api/devices', ['name' => 'x'])->assertForbidden();
    }

    public function test_an_admins_key_can_write(): void
    {
        // Separate test: the auth guard caches the resolved user for the life of a request,
        // so mixing a viewer and an admin key in one test would reuse the first identity.
        $admin = User::factory()->admin()->create();
        $key = $admin->createToken('admin')->plainTextToken;

        $this->withToken($key)->getJson('/api/devices')->assertOk();
        // Empty body is a 422 (validation) - the point is it got PAST the admin gate, not a 403.
        $this->withToken($key)->postJson('/api/devices', [])->assertStatus(422);
    }
}
