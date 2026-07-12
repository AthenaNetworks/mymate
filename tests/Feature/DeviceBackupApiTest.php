<?php

namespace Tests\Feature;

use App\Enums\PollMethod;
use App\Jobs\RunDeviceBackupJob;
use App\Models\Credential;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Per-device config-backup endpoints: opt-in/driver config, "back up
 * now" (queues a job), and read-through history/latest proxies. Admin-gated writes.
 */
class DeviceBackupApiTest extends TestCase
{
    use RefreshDatabase;

    /** Make the Rusted engine look configured (URL + token) for the request under test. */
    private function configureEngine(): void
    {
        config()->set('mymate.backup.url', 'http://127.0.0.1:8410');
        config()->set('mymate.backup.token', 'test-token');
    }

    public function test_enabling_backups_sets_the_driver_and_registers_with_rusted(): void
    {
        $this->configureEngine();
        Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);
        $this->actingAsUser();

        $cred = Credential::factory()->routeros()->create();
        $device = Device::factory()->create(['poll_method' => PollMethod::RouterOs, 'credential_id' => $cred->id]);

        $this->putJson("/api/devices/{$device->id}/backup-config", ['backup_enabled' => true])
            ->assertOk()
            ->assertJsonPath('data.backup_enabled', true)
            ->assertJsonPath('data.backup_driver', 'mikrotik_routeros');

        $this->assertDatabaseHas('devices', ['id' => $device->id, 'backup_enabled' => true, 'backup_driver' => 'mikrotik_routeros']);
        // Rusted was told about the device.
        Http::assertSent(fn ($r) => str_contains($r->url(), '/api/devices') && $r->method() === 'POST');
    }

    public function test_enabling_without_a_resolvable_driver_is_rejected(): void
    {
        $this->configureEngine();
        $this->actingAsUser();

        $device = Device::factory()->create(['poll_method' => PollMethod::Snmp, 'vendor' => 'Acme Widgets']);

        $this->putJson("/api/devices/{$device->id}/backup-config", ['backup_enabled' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('backup_driver');

        $this->assertDatabaseHas('devices', ['id' => $device->id, 'backup_enabled' => false]);
    }

    public function test_disabling_backups_deregisters(): void
    {
        $this->configureEngine();
        Http::fake(['*' => Http::response([], 200)]);
        $this->actingAsUser();

        $device = Device::factory()->create(['poll_method' => PollMethod::RouterOs, 'backup_enabled' => true, 'backup_driver' => 'mikrotik_routeros']);

        $this->putJson("/api/devices/{$device->id}/backup-config", ['backup_enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.backup_enabled', false);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/api/devices/mymate-'.$device->id) && $r->method() === 'DELETE');
    }

    public function test_run_queues_a_backup_job_and_marks_pending(): void
    {
        $this->configureEngine();
        Queue::fake();
        $this->actingAsUser();

        $device = Device::factory()->create(['poll_method' => PollMethod::RouterOs, 'backup_enabled' => true, 'backup_driver' => 'mikrotik_routeros']);

        $this->postJson("/api/devices/{$device->id}/backups")
            ->assertStatus(202)
            ->assertJsonPath('data.backup_status', 'pending');

        Queue::assertPushed(RunDeviceBackupJob::class, fn ($job) => $job->deviceId === $device->id);
    }

    public function test_run_is_rejected_when_the_engine_is_unconfigured(): void
    {
        config()->set('mymate.backup.url', '');
        config()->set('mymate.backup.token', '');
        Queue::fake();
        $this->actingAsUser();

        $device = Device::factory()->create();

        $this->postJson("/api/devices/{$device->id}/backups")->assertStatus(422);
        Queue::assertNothingPushed();
    }

    public function test_history_is_proxied_from_rusted(): void
    {
        $this->configureEngine();
        Http::fake(['*/history' => Http::response([
            ['started_at' => '2026-07-10T02:30:00Z', 'finished_at' => '2026-07-10T02:30:05Z', 'status' => 'success', 'message' => '', 'bytes' => 4096, 'commit' => 'abc123'],
        ], 200)]);
        $this->actingAsUser();

        $device = Device::factory()->create();

        $this->getJson("/api/devices/{$device->id}/backups")
            ->assertOk()
            ->assertJsonPath('data.0.commit', 'abc123')
            ->assertJsonPath('data.0.status', 'success');
    }

    public function test_history_returns_empty_when_engine_unconfigured(): void
    {
        config()->set('mymate.backup.url', '');
        config()->set('mymate.backup.token', '');
        $this->actingAsUser();
        $device = Device::factory()->create();

        $this->getJson("/api/devices/{$device->id}/backups")->assertOk()->assertJsonPath('data', []);
    }

    public function test_latest_config_is_proxied(): void
    {
        $this->configureEngine();
        Http::fake(['*/config' => Http::response("/system identity set name=RB1\n", 200)]);
        $this->actingAsUser();
        $device = Device::factory()->create();

        $this->getJson("/api/devices/{$device->id}/backups/latest")
            ->assertOk()
            ->assertJsonPath('data.config', "/system identity set name=RB1\n");
    }

    public function test_non_admin_cannot_change_backup_config_or_run(): void
    {
        $this->configureEngine();
        $device = Device::factory()->create(['poll_method' => PollMethod::RouterOs]);
        $this->actingAs(User::factory()->create());

        $this->putJson("/api/devices/{$device->id}/backup-config", ['backup_enabled' => true])->assertForbidden();
        $this->postJson("/api/devices/{$device->id}/backups")->assertForbidden();
        // ...but a read-only operator can still view history.
        Http::fake(['*/history' => Http::response([], 200)]);
        $this->getJson("/api/devices/{$device->id}/backups")->assertOk();
    }
}
