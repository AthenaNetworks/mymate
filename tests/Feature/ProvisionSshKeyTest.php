<?php

namespace Tests\Feature;

use App\Models\Credential;
use App\Models\Device;
use App\Enums\PollMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProvisionSshKeyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('mymate.backup.url', 'http://127.0.0.1:8410');
        config()->set('mymate.backup.token', 'test-token');
    }

    private function mikrotik(): Device
    {
        $cred = Credential::factory()->routeros()->create(['username' => 'nms', 'password' => 'pw']);

        return Device::factory()->create([
            'name' => 'CPE1', 'poll_method' => PollMethod::RouterOs, 'credential_id' => $cred->id,
        ]);
    }

    public function test_provisions_a_key_and_stores_it_as_the_ssh_credential(): void
    {
        $this->actingAsUser();
        Http::fake([
            '*/api/provision/mikrotik-ssh-key' => Http::response([
                'user' => 'nms', 'private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nABC\n-----END OPENSSH PRIVATE KEY-----\n",
                'ssh_port' => 22, 'ssh_enabled' => true, 'ssh_enabled_by' => true,
            ], 200),
        ]);
        $device = $this->mikrotik();

        $this->postJson("/api/devices/{$device->id}/provision-ssh-key")
            ->assertOk()
            ->assertJsonPath('ssh_enabled', true)
            ->assertJsonPath('ssh_enabled_by', true);

        $device->refresh();
        $this->assertNotNull($device->ssh_credential_id);
        $this->assertSame('mikrotik_routeros', $device->backup_driver); // switched to SSH backups

        $ssh = Credential::find($device->ssh_credential_id);
        $this->assertSame('ssh', $ssh->type);
        $this->assertSame('nms+ct', $ssh->username);          // RouterOS clean-output suffix
        $this->assertStringContainsString('PRIVATE KEY', $ssh->private_key);

        // My Mate sent the device's API login to Rusted's provision endpoint.
        Http::assertSent(fn ($r) => str_contains($r->url(), '/api/provision/mikrotik-ssh-key')
            && $r['host'] === $device->mgmt_ip && $r['username'] === 'nms');
    }

    public function test_rejects_a_device_without_a_routeros_credential(): void
    {
        $this->actingAsUser();
        $device = Device::factory()->create(['poll_method' => PollMethod::Snmp]);

        $this->postJson("/api/devices/{$device->id}/provision-ssh-key")
            ->assertStatus(422);
    }
}
