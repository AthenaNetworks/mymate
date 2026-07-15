<?php

namespace Tests\Feature;

use App\Models\Credential;
use App\Models\User;
use App\Services\Snmp\SnmpCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SnmpV3CredentialTest extends TestCase
{
    use RefreshDatabase;

    public function test_value_object_maps_a_v2c_credential(): void
    {
        $c = Credential::factory()->create(['type' => 'snmp', 'snmp_version' => '2c', 'snmp_community' => 'public']);
        $vo = SnmpCredential::fromCredential($c->fresh());

        $this->assertSame('2c', $vo->version);
        $this->assertSame('public', $vo->community);
        $this->assertTrue($vo->isUsable());
    }

    public function test_value_object_maps_a_v3_credential_and_gates_usability(): void
    {
        $c = Credential::factory()->create([
            'type' => 'snmp', 'snmp_version' => '3', 'snmp_community' => null,
            'snmp_sec_name' => 'monitor', 'snmp_sec_level' => 'authPriv',
            'snmp_auth_protocol' => 'SHA', 'snmp_auth_passphrase' => 'authsecret1',
            'snmp_priv_protocol' => 'AES', 'snmp_priv_passphrase' => 'privsecret1',
        ]);
        $vo = SnmpCredential::fromCredential($c->fresh());

        $this->assertSame('3', $vo->version);
        $this->assertSame('monitor', $vo->secName);
        $this->assertSame('authsecret1', $vo->authPassphrase);
        $this->assertTrue($vo->isUsable());

        // authPriv without a privacy passphrase is not usable.
        $bad = SnmpCredential::fromCredential(Credential::factory()->create([
            'type' => 'snmp', 'snmp_version' => '3', 'snmp_community' => null,
            'snmp_sec_name' => 'x', 'snmp_sec_level' => 'authPriv',
            'snmp_auth_passphrase' => 'authsecret1', 'snmp_priv_passphrase' => null,
        ])->fresh());
        $this->assertFalse($bad->isUsable());
    }

    public function test_api_creates_a_v3_credential_and_hides_the_passphrases(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $res = $this->actingAs($admin)->postJson('/api/credentials', [
            'name' => 'v3 read', 'type' => 'snmp', 'snmp_version' => '3',
            'snmp_sec_name' => 'monitor', 'snmp_sec_level' => 'authPriv',
            'snmp_auth_protocol' => 'SHA', 'snmp_auth_passphrase' => 'authsecret1',
            'snmp_priv_protocol' => 'AES', 'snmp_priv_passphrase' => 'privsecret1',
        ]);

        $res->assertCreated()
            ->assertJsonPath('data.snmp_version', '3')
            ->assertJsonPath('data.snmp_sec_name', 'monitor')
            ->assertJsonPath('data.has_secret', true)
            ->assertJsonMissingPath('data.snmp_auth_passphrase');

        // Stored encrypted, retrievable via the model cast.
        $c = Credential::firstWhere('name', 'v3 read');
        $this->assertSame('authsecret1', $c->snmp_auth_passphrase);
    }

    public function test_api_rejects_a_v3_credential_missing_its_user(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->postJson('/api/credentials', [
            'name' => 'bad v3', 'type' => 'snmp', 'snmp_version' => '3', 'snmp_sec_level' => 'authNoPriv',
            'snmp_auth_passphrase' => 'authsecret1',
        ])->assertStatus(422)->assertJsonValidationErrors('snmp_sec_name');
    }
}
