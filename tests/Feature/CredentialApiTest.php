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

        // SSH needs a username + password, like RouterOS.
        $this->postJson('/api/credentials', ['name' => 'Y', 'type' => 'ssh'])
            ->assertStatus(422)->assertJsonValidationErrors(['username', 'password']);
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
