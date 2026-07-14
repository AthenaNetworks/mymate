<?php

namespace Tests\Feature;

use App\Models\Credential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CredentialApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_without_exposing_secrets(): void
    {
        $this->actingAsUser();
        Credential::factory()->create(['type' => 'snmp', 'snmp_community' => 's3cr3t-community', 'name' => 'Shared SNMP']);

        $res = $this->getJson('/api/credentials')->assertOk()->assertJsonCount(1, 'data');

        $this->assertStringNotContainsString('s3cr3t-community', json_encode($res->json()));
        $res->assertJsonPath('data.0.has_secret', true);
    }

    public function test_creates_a_credential_without_leaking_or_storing_plaintext(): void
    {
        $this->actingAsUser();

        $res = $this->postJson('/api/credentials', [
            'name' => 'RB-EDGE', 'type' => 'routeros', 'username' => 'admin', 'password' => 'p@ss-12345',
        ])->assertCreated();

        $this->assertStringNotContainsString('p@ss-12345', json_encode($res->json())); // not returned
        $row = DB::table('credentials')->where('name', 'RB-EDGE')->first();
        $this->assertStringNotContainsString('p@ss-12345', (string) $row->password); // encrypted at rest
    }

    public function test_update_keeps_the_existing_secret_when_blank(): void
    {
        $this->actingAsUser();
        $cred = Credential::factory()->create(['type' => 'routeros', 'username' => 'admin', 'password' => 'orig-secret']);

        $this->putJson("/api/credentials/{$cred->id}", ['name' => 'Renamed', 'password' => ''])
            ->assertOk()->assertJsonPath('data.name', 'Renamed');

        $this->assertSame('orig-secret', $cred->fresh()->password); // unchanged (write-only blank = keep)
    }

    public function test_validates_required_secret_by_type(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/credentials', ['name' => 'X', 'type' => 'snmp'])
            ->assertStatus(422)->assertJsonValidationErrors(['snmp_community']);
    }

    public function test_creates_an_ssh_credential_for_backups(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/credentials', [
            'name' => 'Backup SSH', 'type' => 'ssh', 'username' => 'backup', 'password' => 'sup3r-secret-1',
        ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'ssh')
            ->assertJsonPath('data.has_secret', true);

        // SSH needs a username + a password OR a key.
        $this->postJson('/api/credentials', ['name' => 'Y', 'type' => 'ssh'])
            ->assertStatus(422)->assertJsonValidationErrors(['username', 'password']);
    }

    public function test_creates_an_ssh_credential_with_a_private_key_and_no_password(): void
    {
        $this->actingAsUser();
        $key = "-----BEGIN OPENSSH PRIVATE KEY-----\nAAAAsecretkeymaterial\n-----END OPENSSH PRIVATE KEY-----";

        $res = $this->postJson('/api/credentials', [
            'name' => 'Key SSH', 'type' => 'ssh', 'username' => 'backup', 'private_key' => $key,
        ])
            ->assertCreated()
            ->assertJsonPath('data.has_secret', true)
            ->assertJsonPath('data.has_private_key', true);

        // The key is never returned in the API payload.
        $res->assertDontSee('secretkeymaterial');

        $credential = \App\Models\Credential::firstOrFail();
        $this->assertSame($key, $credential->private_key); // round-trips through the encrypted cast
        // ...and is stored encrypted, not as plaintext.
        $raw = \Illuminate\Support\Facades\DB::table('credentials')->where('id', $credential->id)->value('private_key');
        $this->assertNotSame($key, $raw);
    }

    public function test_update_keeps_the_existing_private_key_when_blank(): void
    {
        $this->actingAsUser();
        $credential = \App\Models\Credential::factory()->create([
            'type' => 'ssh', 'username' => 'backup', 'password' => null, 'private_key' => 'KEEP-ME',
        ]);

        $this->putJson("/api/credentials/{$credential->id}", ['name' => 'Renamed', 'private_key' => ''])
            ->assertOk();

        $this->assertSame('KEEP-ME', $credential->fresh()->private_key);
    }

    public function test_deletes_a_credential(): void
    {
        $this->actingAsUser();
        $cred = Credential::factory()->create();

        $this->deleteJson("/api/credentials/{$cred->id}")->assertNoContent();
        $this->assertDatabaseMissing('credentials', ['id' => $cred->id]);
    }

    public function test_requires_authentication(): void
    {
        Credential::factory()->create();

        $this->getJson('/api/credentials')->assertUnauthorized();
    }
}
