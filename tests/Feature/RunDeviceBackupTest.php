<?php

namespace Tests\Feature;

use App\Actions\Backup\RunDeviceBackup;
use App\Enums\BackupStatus;
use App\Enums\PollMethod;
use App\Models\Credential;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The RunDeviceBackup action: registers the device with Rusted, triggers
 * the capture, and mirrors the result onto the device. Rusted's HTTP API is faked.
 */
class RunDeviceBackupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('mymate.backup.url', 'http://127.0.0.1:8410');
        config()->set('mymate.backup.token', 'test-token');
    }

    private function routerosDevice(): Device
    {
        $cred = Credential::factory()->routeros()->create();

        return Device::factory()->create([
            'poll_method' => PollMethod::RouterOs,
            'credential_id' => $cred->id,
            'backup_enabled' => true,
            'backup_driver' => 'mikrotik_routeros',
        ]);
    }

    public function test_a_successful_backup_marks_ok_with_commit(): void
    {
        Http::fake([
            '*/api/credentials' => Http::response(['status' => 'ok'], 200),
            '*/api/devices/*/backup' => Http::response(['status' => 'success', 'commit' => 'deadbeef', 'message' => 'captured'], 200),
            '*' => Http::response(['status' => 'ok'], 200),
        ]);

        $device = $this->routerosDevice();
        app(RunDeviceBackup::class)($device);

        $device->refresh();
        $this->assertSame(BackupStatus::Ok, $device->backup_status);
        $this->assertSame('deadbeef', $device->backup_commit);
        $this->assertNotNull($device->backup_at);
    }

    public function test_unchanged_backup_maps_to_unchanged(): void
    {
        Http::fake([
            '*/api/devices/*/backup' => Http::response(['status' => 'unchanged', 'commit' => 'oldhash'], 200),
            '*' => Http::response(['status' => 'ok'], 200),
        ]);

        $device = $this->routerosDevice();
        app(RunDeviceBackup::class)($device);

        $this->assertSame(BackupStatus::Unchanged, $device->refresh()->backup_status);
    }

    public function test_a_failed_result_surfaces_rusteds_message_on_the_device(): void
    {
        // The empty-config case (#41): Rusted reports status "failed" with a specific reason. That
        // reason must land on backup_message so the Backups page shows *why*, not a generic failure.
        Http::fake([
            '*/api/devices/*/backup' => Http::response(['status' => 'failed', 'message' => 'captured empty configuration'], 200),
            '*' => Http::response(['status' => 'ok'], 200),
        ]);

        $device = $this->routerosDevice();
        app(RunDeviceBackup::class)($device);

        $device->refresh();
        $this->assertSame(BackupStatus::Failed, $device->backup_status);
        $this->assertSame('captured empty configuration', $device->backup_message);
    }

    public function test_a_failure_reported_via_an_error_status_still_surfaces_the_message(): void
    {
        // Rusted may return the same structured result with a non-2xx status; RustedClient must
        // still surface the specific message rather than a generic HTTP error.
        Http::fake([
            '*/api/devices/*/backup' => Http::response(['status' => 'failed', 'message' => 'captured empty configuration'], 500),
            '*' => Http::response(['status' => 'ok'], 200),
        ]);

        $device = $this->routerosDevice();
        app(RunDeviceBackup::class)($device);

        $device->refresh();
        $this->assertSame(BackupStatus::Failed, $device->backup_status);
        $this->assertSame('captured empty configuration', $device->backup_message);
    }

    public function test_a_failed_capture_marks_failed_and_rethrows(): void
    {
        Http::fake([
            '*/api/devices/*/backup' => Http::response('ssh: connection refused', 500),
            '*' => Http::response(['status' => 'ok'], 200),
        ]);

        $device = $this->routerosDevice();

        try {
            app(RunDeviceBackup::class)($device);
            $this->fail('Expected the failed backup to throw.');
        } catch (\Throwable) {
            // expected - the Job's failed() handler relies on this
        }

        $this->assertSame(BackupStatus::Failed, $device->refresh()->backup_status);
        $this->assertNotNull($device->backup_message);
    }

    public function test_a_dedicated_ssh_credential_is_preferred_over_the_poll_credential(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);

        $poll = Credential::factory()->routeros()->create(['username' => 'polluser']);
        $ssh = Credential::factory()->ssh()->create(['username' => 'sshuser', 'password' => 'sshpass']);
        $device = Device::factory()->create([
            'poll_method' => PollMethod::RouterOs,
            'credential_id' => $poll->id,
            'ssh_credential_id' => $ssh->id,
            'backup_enabled' => true,
            'backup_driver' => 'mikrotik_routeros',
        ]);

        app(RunDeviceBackup::class)($device);

        // The credential Rusted is told to use must be the SSH one, not the poll one.
        Http::assertSent(fn ($req) => str_contains($req->url(), '/api/credentials')
            && ($req['name'] ?? null) === "mymate-cred-{$ssh->id}"
            && ($req['username'] ?? null) === 'sshuser');
    }

    public function test_missing_credential_and_no_fallback_fails_cleanly(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);

        // SNMP device, no username on its credential, no Settings SSH fallback -> can't back up.
        $device = Device::factory()->create([
            'poll_method' => PollMethod::Snmp,
            'backup_enabled' => true,
            'backup_driver' => 'mikrotik_routeros',
        ]);

        try {
            app(RunDeviceBackup::class)($device);
            $this->fail('Expected a credential-resolution failure.');
        } catch (\Throwable) {
            // expected
        }

        $this->assertSame(BackupStatus::Failed, $device->refresh()->backup_status);
    }
}
