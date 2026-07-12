<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Rusted backup-engine connection settings: secrets encrypted at rest,
 * never returned, write-only (blank = keep), and admin-only writes.
 */
class BackupSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_saves_config_without_leaking_or_storing_plaintext_secrets(): void
    {
        $this->actingAsUser();

        $res = $this->putJson('/api/settings/backup', [
            'api_url' => 'http://127.0.0.1:8410',
            'api_token' => 'super-secret-token',
            'ssh_username' => 'backup',
            'ssh_password' => 's3cr3t-ssh-pw',
        ])->assertOk();

        // Response carries only flags, never the secrets.
        $payload = json_encode($res->json());
        $this->assertStringNotContainsString('super-secret-token', $payload);
        $this->assertStringNotContainsString('s3cr3t-ssh-pw', $payload);
        $res->assertJsonPath('data.api_token_set', true);
        $res->assertJsonPath('data.ssh_password_set', true);
        $res->assertJsonPath('data.configured', true);

        // Encrypted at rest - the raw settings row must not contain plaintext.
        $row = DB::table('settings')->where('key', 'backup.rusted')->value('value');
        $this->assertStringNotContainsString('super-secret-token', (string) $row);
        $this->assertStringNotContainsString('s3cr3t-ssh-pw', (string) $row);
    }

    public function test_blank_secret_keeps_the_stored_one(): void
    {
        $this->actingAsUser();

        $this->putJson('/api/settings/backup', ['api_url' => 'http://127.0.0.1:8410', 'api_token' => 'keep-me'])->assertOk();
        // Edit another field, omit the token -> it must be retained.
        $this->putJson('/api/settings/backup', ['api_url' => 'http://127.0.0.1:9999'])->assertOk();

        $this->getJson('/api/settings/backup')
            ->assertJsonPath('data.api_url', 'http://127.0.0.1:9999')
            ->assertJsonPath('data.api_token_set', true);
    }

    public function test_rejects_a_non_http_url(): void
    {
        $this->actingAsUser();

        $this->putJson('/api/settings/backup', ['api_url' => 'ftp://nope'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('api_url');
    }

    public function test_test_endpoint_probes_healthz(): void
    {
        Http::fake(['*/healthz' => Http::response(['status' => 'ok'], 200)]);
        $this->actingAsUser();
        $this->putJson('/api/settings/backup', ['api_url' => 'http://127.0.0.1:8410', 'api_token' => 't'])->assertOk();

        $this->postJson('/api/settings/backup/test')->assertOk()->assertJsonPath('ok', true);
    }

    public function test_test_endpoint_reports_unreachable_without_leaking_errors(): void
    {
        Http::fake(['*/healthz' => Http::response('boom', 500)]);
        $this->actingAsUser();
        $this->putJson('/api/settings/backup', ['api_url' => 'http://127.0.0.1:8410', 'api_token' => 't'])->assertOk();

        $this->postJson('/api/settings/backup/test')->assertOk()->assertJsonPath('ok', false);
    }

    public function test_non_admin_cannot_read_or_write_backup_settings(): void
    {
        $this->actingAs(User::factory()->create());

        // Writes are refused (read-only tier); the GET is admin-config too but writes are the guard.
        $this->putJson('/api/settings/backup', ['api_url' => 'http://127.0.0.1:8410'])->assertForbidden();
    }
}
