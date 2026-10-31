<?php

namespace Tests\Feature;

use App\Actions\Cmr\SyncCmrDevices;
use App\Enums\DiscoveryStatus;
use App\Enums\PollMethod;
use App\Models\Device;
use App\Models\DiscoveryCandidate;
use App\Models\User;
use App\Support\CmrSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The CMR controller integration: settings, the minute sync from the cmr-ctrl admin API into the
 * review queue, and approving a paired device into inventory.
 */
class CmrControllerTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'http://127.0.0.1:8411';

    private function configure(): void
    {
        app(CmrSettings::class)->save(['enabled' => true, 'api_url' => self::API, 'api_token' => 'cmr-secret-token']);
    }

    /** @param  array<string,mixed>  $over */
    private function row(array $over = []): array
    {
        return $over + [
            'id' => '48:a9:8a:01:02:03',
            'mac' => '48:a9:8a:01:02:03',
            'identity' => 'tower-7-crs',
            'board' => 'CRS326-24G-2S+',
            'arch' => 'arm',
            'os' => '7.26',
            'connected' => true,
            'last_remote_addr' => '203.0.113.9',
            'first_seen' => '2026-10-31T01:02:03.123456789Z',
            'last_seen' => '2026-10-31T01:05:00.987654321Z',
        ];
    }

    /** @param  array<int, array<string,mixed>>  $rows */
    private function fakeDevices(array $rows): void
    {
        Http::fake([self::API.'/devices' => Http::response($rows)]);
    }

    public function test_a_paired_device_lands_in_the_review_queue_not_inventory(): void
    {
        $this->configure();
        $this->fakeDevices([$this->row()]);

        $r = app(SyncCmrDevices::class)();

        $this->assertSame(['devices' => 0, 'candidates' => 1], $r);
        $this->assertSame(0, Device::count());
        $c = DiscoveryCandidate::sole();
        $this->assertSame('cmr', $c->source);
        $this->assertSame(DiscoveryStatus::New, $c->status);
        $this->assertSame('203.0.113.9', $c->ip);
        $this->assertSame('tower-7-crs', $c->sysname);
        $this->assertSame('CRS326-24G-2S+', $c->board);
        $this->assertSame('2026-10-31 01:05:00', $c->last_seen->utc()->format('Y-m-d H:i:s'));
        Http::assertSent(fn ($req) => $req->hasHeader('Authorization', 'Bearer cmr-secret-token'));
    }

    public function test_two_devices_behind_one_nat_address_are_separate_candidates(): void
    {
        $this->configure();
        $this->fakeDevices([$this->row(), $this->row(['id' => '48:a9:8a:0a:0b:0c', 'mac' => '48:a9:8a:0a:0b:0c'])]);

        app(SyncCmrDevices::class)();

        $this->assertSame(2, DiscoveryCandidate::where('ip', '203.0.113.9')->count());
    }

    public function test_an_ignored_device_stays_ignored_when_it_re_pairs(): void
    {
        $this->configure();
        $this->fakeDevices([$this->row()]);
        app(SyncCmrDevices::class)();
        DiscoveryCandidate::sole()->update(['status' => DiscoveryStatus::Ignored]);

        app(SyncCmrDevices::class)();

        $this->assertSame(DiscoveryStatus::Ignored, DiscoveryCandidate::sole()->status);
    }

    public function test_a_record_with_no_address_yet_is_skipped(): void
    {
        $this->configure();
        $this->fakeDevices([$this->row(['last_remote_addr' => '', 'first_seen' => '0001-01-01T00:00:00Z'])]);

        app(SyncCmrDevices::class)();

        $this->assertSame(0, DiscoveryCandidate::count());
    }

    public function test_approving_adds_a_ping_only_device_carrying_its_cmr_identity(): void
    {
        Queue::fake();
        $this->configure();
        $this->fakeDevices([$this->row()]);
        app(SyncCmrDevices::class)();
        $this->actingAsUser();

        $this->postJson('/api/discovery-candidates/'.DiscoveryCandidate::sole()->id.'/approve')->assertCreated();

        $d = Device::sole();
        $this->assertSame('tower-7-crs', $d->name);
        $this->assertSame('203.0.113.9', $d->mgmt_ip);
        $this->assertSame(PollMethod::None, $d->poll_method);
        $this->assertSame('48:a9:8a:01:02:03', $d->cmr_device_id);
        $this->assertSame(DiscoveryStatus::Approved, DiscoveryCandidate::sole()->status);
    }

    public function test_approving_links_a_device_already_monitored_at_that_address(): void
    {
        Queue::fake();
        $this->configure();
        $existing = Device::factory()->create(['name' => 'already-here', 'mgmt_ip' => '203.0.113.9']);
        $this->fakeDevices([$this->row()]);
        app(SyncCmrDevices::class)();
        $this->actingAsUser();

        $this->postJson('/api/discovery-candidates/'.DiscoveryCandidate::sole()->id.'/approve')->assertCreated();

        $this->assertSame(1, Device::count());
        $this->assertSame('48:a9:8a:01:02:03', $existing->fresh()->cmr_device_id);
        $this->assertSame('already-here', $existing->fresh()->name);
    }

    public function test_a_second_device_behind_the_same_nat_is_refused_with_a_reason(): void
    {
        Queue::fake();
        $this->configure();
        $this->fakeDevices([$this->row(), $this->row(['id' => '48:a9:8a:0a:0b:0c', 'mac' => '48:a9:8a:0a:0b:0c'])]);
        app(SyncCmrDevices::class)();
        $this->actingAsUser();
        [$first, $second] = DiscoveryCandidate::orderBy('cmr_device_id')->get()->all();

        $this->postJson("/api/discovery-candidates/{$first->id}/approve")->assertCreated();
        $this->postJson("/api/discovery-candidates/{$second->id}/approve")
            ->assertUnprocessable()
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'behind NAT'));

        $this->assertSame(1, Device::count());
        $this->assertSame(DiscoveryStatus::New, $second->fresh()->status);
    }

    public function test_sync_refreshes_a_known_device_and_re_offers_a_deleted_one(): void
    {
        Queue::fake();
        $this->configure();
        $this->fakeDevices([$this->row()]);
        app(SyncCmrDevices::class)();
        $this->actingAsUser();
        $this->postJson('/api/discovery-candidates/'.DiscoveryCandidate::sole()->id.'/approve')->assertCreated();
        $device = Device::sole();
        $device->update(['mgmt_ip' => '10.20.0.5', 'cmr_connected' => false]); // operator fixed the NAT address

        $this->assertSame(['devices' => 1, 'candidates' => 0], app(SyncCmrDevices::class)());
        $this->assertTrue($device->fresh()->cmr_connected);
        $this->assertSame('10.20.0.5', $device->fresh()->mgmt_ip); // the sync never overrides it

        $device->delete();
        app(SyncCmrDevices::class)();
        $this->assertSame(DiscoveryStatus::New, DiscoveryCandidate::sole()->status);
    }

    public function test_a_device_the_daemon_forgot_is_marked_disconnected(): void
    {
        $this->configure();
        $device = Device::factory()->create(['cmr_device_id' => 'aa:bb:cc:dd:ee:ff', 'cmr_connected' => true]);
        $this->fakeDevices([]);

        app(SyncCmrDevices::class)();

        $this->assertFalse($device->fresh()->cmr_connected);
    }

    public function test_the_sync_command_does_nothing_until_turned_on(): void
    {
        Http::fake();

        $this->artisan('mymate:cmr:sync')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_the_sync_command_fails_cleanly_when_the_daemon_is_down(): void
    {
        $this->configure();
        Http::fake([self::API.'/devices' => Http::response('nope', 502)]);

        $this->artisan('mymate:cmr:sync')->assertFailed();
    }

    public function test_a_subnet_prune_leaves_cmr_candidates_alone(): void
    {
        $this->configure();
        $this->fakeDevices([$this->row()]);
        app(SyncCmrDevices::class)();

        $this->artisan('mymate:discovery:prune')->assertSuccessful();

        $this->assertSame(1, DiscoveryCandidate::count());
    }

    public function test_settings_never_return_the_token_and_show_the_fingerprint(): void
    {
        Http::fake([self::API.'/identity' => Http::response(['fingerprint' => 'ab12cd', 'listen_addr' => '[::]:54321', 'version' => 'v0.1.1'])]);
        $this->actingAsUser();

        $res = $this->putJson('/api/settings/cmr', [
            'enabled' => true,
            'api_url' => self::API,
            'api_token' => 'cmr-secret-token',
            'public_host' => 'mymate.example.net',
        ])->assertOk();

        $this->assertStringNotContainsString('cmr-secret-token', json_encode($res->json()));
        $this->assertStringNotContainsString('cmr-secret-token', (string) DB::table('settings')->where('key', 'cmr.controller')->value('value'));
        $res->assertJsonPath('data.api_token_set', true)
            ->assertJsonPath('data.effective_host', 'mymate.example.net')
            ->assertJsonPath('data.identity.fingerprint', 'ab12cd');

        // Omitting the token keeps it.
        $this->putJson('/api/settings/cmr', ['enabled' => false, 'api_url' => self::API])
            ->assertOk()->assertJsonPath('data.api_token_set', true)->assertJsonPath('data.enabled', false);
    }

    public function test_settings_are_admin_only(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        $this->getJson('/api/settings/cmr')->assertForbidden();
        $this->putJson('/api/settings/cmr', ['enabled' => true, 'api_url' => self::API])->assertForbidden();
    }

    public function test_configure_command_reads_the_token_file(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'cmr');
        file_put_contents($file, "file-token\n");

        $this->artisan('mymate:cmr:configure', ['--url' => self::API, '--token-file' => $file])->assertSuccessful();
        unlink($file);

        $s = app(CmrSettings::class);
        $this->assertTrue($s->active());
        $this->assertSame('file-token', $s->apiToken());
    }
}
