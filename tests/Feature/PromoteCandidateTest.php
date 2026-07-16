<?php

namespace Tests\Feature;

use App\Actions\Devices\CreateDevice;
use App\Actions\Discovery\IgnoreCandidate;
use App\Actions\Discovery\PromoteCandidate;
use App\Enums\DiscoveryStatus;
use App\Enums\PollMethod;
use App\Jobs\DiscoverInterfacesJob;
use App\Jobs\PollInterfacesJob;
use App\Models\Credential;
use App\Models\Device;
use App\Models\DiscoveryCandidate;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PromoteCandidateTest extends TestCase
{
    use RefreshDatabase;

    public function test_approving_creates_a_device_with_the_matched_credential_and_kicks_jobs(): void
    {
        Queue::fake();
        $cred = Credential::factory()->routeros()->create();
        $candidate = DiscoveryCandidate::factory()->create([
            'ip' => '10.80.111.5',
            'sysname' => 'CPE1',
            'detected_method' => PollMethod::RouterOs,
            'matched_credential_id' => $cred->id,
            'status' => DiscoveryStatus::New,
        ]);

        $device = app(PromoteCandidate::class)($candidate);

        $this->assertSame('CPE1', $device->name);
        $this->assertSame('10.80.111.5', $device->mgmt_ip);
        $this->assertSame(PollMethod::RouterOs, $device->poll_method);
        $this->assertSame($cred->id, $device->credential_id);
        $this->assertSame(DiscoveryStatus::Approved, $candidate->fresh()->status);

        Queue::assertPushed(DiscoverInterfacesJob::class, fn ($j) => $j->deviceId === $device->id);
        Queue::assertPushed(PollInterfacesJob::class, fn ($j) => $j->deviceId === $device->id);
    }

    public function test_approving_links_both_the_poll_and_ssh_credentials(): void
    {
        Queue::fake();
        $poll = Credential::factory()->routeros()->create();
        $ssh = Credential::factory()->create(['type' => 'ssh', 'username' => 'backup']);
        $candidate = DiscoveryCandidate::factory()->create([
            'ip' => '10.80.111.6',
            'detected_method' => PollMethod::RouterOs,
            'matched_credential_id' => $poll->id,
            'matched_ssh_credential_id' => $ssh->id,
            'status' => DiscoveryStatus::New,
        ]);

        $device = app(PromoteCandidate::class)($candidate);

        $this->assertSame($poll->id, $device->credential_id);
        $this->assertSame($ssh->id, $device->ssh_credential_id);
    }

    public function test_an_ssh_only_match_promotes_to_a_ping_only_device_with_backups(): void
    {
        Queue::fake();
        $ssh = Credential::factory()->create(['type' => 'ssh', 'username' => 'backup']);
        $candidate = DiscoveryCandidate::factory()->create([
            'ip' => '10.80.111.7',
            'detected_method' => null, // nothing to poll, but SSH works
            'matched_credential_id' => null,
            'matched_ssh_credential_id' => $ssh->id,
            'status' => DiscoveryStatus::New,
        ]);

        $device = app(PromoteCandidate::class)($candidate);

        $this->assertSame(PollMethod::None, $device->poll_method);
        $this->assertNull($device->credential_id);
        $this->assertSame($ssh->id, $device->ssh_credential_id);
    }

    public function test_approving_an_unidentified_candidate_is_rejected(): void
    {
        // : discovery no longer queues unidentified hosts, and a legacy
        // unidentified candidate can't be promoted - there's nothing to poll.
        Queue::fake();
        $candidate = DiscoveryCandidate::factory()->create([
            'ip' => '10.0.0.9',
            'sysname' => null,
            'detected_method' => null,
            'matched_credential_id' => null,
            'status' => DiscoveryStatus::New,
        ]);

        try {
            app(PromoteCandidate::class)($candidate);
            $this->fail('Expected a RuntimeException promoting an unidentified candidate.');
        } catch (\RuntimeException) {
            // expected
        }

        // Transaction rolled back: no device, candidate untouched.
        $this->assertSame(0, Device::count());
        $this->assertSame(DiscoveryStatus::New, $candidate->fresh()->status);
    }

    public function test_approving_does_not_create_a_duplicate_device_for_the_same_ip(): void
    {
        Queue::fake();
        $existing = Device::factory()->create(['mgmt_ip' => '10.0.0.9']);
        $candidate = DiscoveryCandidate::factory()->create(['ip' => '10.0.0.9']);

        $device = app(PromoteCandidate::class)($candidate);

        $this->assertSame($existing->id, $device->id);
        $this->assertSame(1, Device::count());
        $this->assertSame(DiscoveryStatus::Approved, $candidate->fresh()->status);
    }

    /**
     * Adversarial-review finding (2026-07-01): the "does a device exist?" check was
     * a check-then-act race - two near-simultaneous "Approve" clicks could both pass
     * it before either committed. Closed by a DB-level unique index on mgmt_ip
     * (migration 2026_07_01_000003); this proves the resulting unique-violation is
     * caught and recovered from (reuse the winner) rather than surfacing as a 500.
     */
    public function test_recovers_gracefully_when_a_concurrent_request_wins_the_race(): void
    {
        Queue::fake();
        $candidate = DiscoveryCandidate::factory()->create([
            'ip' => '10.0.0.9', 'detected_method' => PollMethod::Snmp,
        ]);

        // Insert (and later clean up) the "winner" via a genuinely separate DB
        // connection/session - an Eloquent insert on this test's own connection
        // would live inside PromoteCandidate's nested-transaction savepoint and get
        // rolled back along with the exception below, unlike a real concurrent
        // request's already-committed row on its own connection. Because it's a
        // separate autocommitting session, RefreshDatabase's usual per-test
        // transaction rollback won't clean it up - this test must do that itself.
        config(['database.connections.pgsql_race_winner' => config('database.connections.pgsql')]);

        $this->mock(CreateDevice::class, function ($mock): void {
            $mock->shouldReceive('__invoke')->once()->andReturnUsing(function (): never {
                $attributes = Device::factory()->make(['mgmt_ip' => '10.0.0.9'])->getAttributes();
                DB::connection('pgsql_race_winner')->table('devices')->insert($attributes);

                throw new QueryException(
                    'pgsql', 'insert into "devices" ...', [],
                    new \Exception('duplicate key value violates unique constraint "devices_mgmt_ip_unique"', '23505'),
                );
            });
        });

        try {
            $device = app(PromoteCandidate::class)($candidate);

            $this->assertSame('10.0.0.9', $device->mgmt_ip);
            $this->assertSame(1, Device::count()); // no duplicate
            $this->assertSame(DiscoveryStatus::Approved, $candidate->fresh()->status);
        } finally {
            DB::connection('pgsql_race_winner')->table('devices')->where('mgmt_ip', '10.0.0.9')->delete();
            DB::purge('pgsql_race_winner');
        }
    }

    public function test_ignoring_marks_the_candidate_ignored(): void
    {
        $candidate = DiscoveryCandidate::factory()->create(['status' => DiscoveryStatus::New]);

        app(IgnoreCandidate::class)($candidate);

        $this->assertSame(DiscoveryStatus::Ignored, $candidate->fresh()->status);
    }
}
