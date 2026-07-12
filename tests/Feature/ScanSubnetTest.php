<?php

namespace Tests\Feature;

use App\Actions\Discovery\ScanSubnet;
use App\Enums\DiscoveryStatus;
use App\Enums\PollMethod;
use App\Models\Credential;
use App\Models\Device;
use App\Models\DiscoveryCandidate;
use App\Models\Subnet;
use App\Services\Ping\Pinger;
use App\Services\RouterOs\RouterOsClient;
use App\Services\RouterOs\RouterOsClientException;
use App\Services\Snmp\SnmpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Logger as Monolog;
use Tests\Support\FakeRouterOsClient;
use Tests\Support\FakeSnmpClient;
use Tests\TestCase;

class ScanSubnetTest extends TestCase
{
    use RefreshDatabase;

    /** Bind the network fakes the scan/prober run through (no hardware). */
    private function bindFakes(array $reachable, ?FakeSnmpClient $snmp = null, ?FakeRouterOsClient $routerOs = null): void
    {
        config(['mymate.discovery.attempt_delay_ms' => 0]); // no real sleeping in tests

        $this->app->bind(Pinger::class, fn () => new class($reachable) implements Pinger
        {
            public function __construct(private array $reachable) {}

            public function reachable(array $ips): array
            {
                return array_values(array_intersect($ips, $this->reachable));
            }
        });

        $this->app->bind(SnmpClient::class, fn () => $snmp ?? new FakeSnmpClient);
        $this->app->bind(RouterOsClient::class, fn () => $routerOs ?? new FakeRouterOsClient);
    }

    private function snmpFake(string $community, string $sysname): FakeSnmpClient
    {
        $snmp = new FakeSnmpClient;
        $snmp->throwOnUnknownGet = true;
        $snmp->getsByCommunity[$community] = [(string) config('mymate.snmp.oids.sys_name') => $sysname];

        return $snmp;
    }

    public function test_creates_candidates_for_responders_and_records_the_match(): void
    {
        $cred = Credential::factory()->create(['type' => 'snmp', 'snmp_community' => 'pub']);
        $subnet = Subnet::factory()->create(['cidr' => '10.80.111.0/29']); // usable .1-.6
        $this->bindFakes(['10.80.111.1', '10.80.111.5'], $this->snmpFake('pub', 'CPE1'));

        $new = app(ScanSubnet::class)($subnet);

        $this->assertSame(2, $new);
        $this->assertDatabaseCount('discovery_candidates', 2);

        $candidate = DiscoveryCandidate::where('ip', '10.80.111.1')->firstOrFail();
        $this->assertSame(DiscoveryStatus::New, $candidate->status);
        $this->assertSame(PollMethod::Snmp, $candidate->detected_method);
        $this->assertSame('CPE1', $candidate->sysname);
        $this->assertSame($cred->id, $candidate->matched_credential_id);
        $this->assertNotNull($candidate->first_seen);
        $this->assertNotNull($candidate->last_seen);
        $this->assertNotNull($subnet->fresh()->last_scanned_at);
    }

    public function test_skips_ips_that_are_already_devices(): void
    {
        Credential::factory()->create(['type' => 'snmp', 'snmp_community' => 'pub']);
        Device::factory()->create(['mgmt_ip' => '10.80.111.1']);
        $subnet = Subnet::factory()->create(['cidr' => '10.80.111.0/29']);
        $this->bindFakes(['10.80.111.1', '10.80.111.5'], $this->snmpFake('pub', 'CPE1'));

        $new = app(ScanSubnet::class)($subnet);

        $this->assertSame(1, $new);
        $this->assertDatabaseMissing('discovery_candidates', ['ip' => '10.80.111.1']);
        $this->assertDatabaseHas('discovery_candidates', ['ip' => '10.80.111.5']);
    }

    public function test_job_never_scans_an_agent_assigned_subnet_centrally(): void
    {
        $agent = \App\Models\Agent::factory()->create();
        $subnet = Subnet::factory()->create(['cidr' => '10.80.111.0/29', 'agent_id' => $agent->id]);

        $scan = \Mockery::mock(ScanSubnet::class);
        $scan->shouldNotReceive('__invoke'); // its agent scans it, not the central sweep

        (new \App\Jobs\ScanSubnetJob($subnet->id))->handle($scan);

        $this->assertNull($subnet->fresh()->last_scanned_at);
    }

    public function test_rescan_bumps_last_seen_without_duplicating(): void
    {
        $subnet = Subnet::factory()->create(['cidr' => '10.80.111.0/29']);
        $candidate = DiscoveryCandidate::factory()->create([
            'ip' => '10.80.111.5',
            'status' => DiscoveryStatus::New,
            'last_seen' => now()->subDay(),
        ]);
        $this->bindFakes(['10.80.111.5']);

        $new = app(ScanSubnet::class)($subnet);

        $this->assertSame(0, $new);
        $this->assertDatabaseCount('discovery_candidates', 1);
        $this->assertTrue($candidate->fresh()->last_seen->greaterThan(now()->subHour()));
    }

    public function test_rescan_never_resurrects_an_ignored_candidate(): void
    {
        $subnet = Subnet::factory()->create(['cidr' => '10.80.111.0/29']);
        $candidate = DiscoveryCandidate::factory()->create([
            'ip' => '10.80.111.5',
            'status' => DiscoveryStatus::Ignored,
            'last_seen' => now()->subDay(),
        ]);
        $this->bindFakes(['10.80.111.5']);

        $new = app(ScanSubnet::class)($subnet);

        $this->assertSame(0, $new);
        $this->assertSame(DiscoveryStatus::Ignored, $candidate->fresh()->status);
        $this->assertTrue($candidate->fresh()->last_seen->greaterThan(now()->subHour())); // still bumped
    }

    public function test_skips_a_responder_that_matches_nothing(): void
    {
        // Responds to ICMP but fails BOTH SNMP and RouterOS auth -> not actionable
        // (nothing to poll), so it must NOT be queued as a candidate.
        Credential::factory()->create(['type' => 'snmp', 'snmp_community' => 'nomatch']);
        $subnet = Subnet::factory()->create(['cidr' => '10.80.111.0/29']);

        $snmp = new FakeSnmpClient;
        $snmp->throwOnUnknownGet = true;
        $routerOs = new FakeRouterOsClient(failOpenWith: new RouterOsClientException('filtered'));
        $this->bindFakes(['10.80.111.5'], $snmp, $routerOs);

        $new = app(ScanSubnet::class)($subnet);

        $this->assertSame(0, $new);
        $this->assertDatabaseCount('discovery_candidates', 0);
    }

    public function test_does_not_log_plaintext_credentials(): void
    {
        Credential::factory()->create(['type' => 'snmp', 'snmp_community' => 's3cr3t-community']);
        Credential::factory()->routeros()->create(['username' => 'admin', 'password' => 's3cr3t-pass']);
        $subnet = Subnet::factory()->create(['cidr' => '10.80.111.0/29']);

        // SNMP fails -> RouterOS login is attempted (so the password is actually used).
        $snmp = new FakeSnmpClient;
        $snmp->throwOnUnknownGet = true;
        $routerOs = new FakeRouterOsClient(replies: ['/system/identity/print' => [['name' => 'RB']]]);
        $this->bindFakes(['10.80.111.5'], $snmp, $routerOs);

        $handler = $this->captureEngineLog();
        app(ScanSubnet::class)($subnet);

        $blob = '';
        foreach ($handler->getRecords() as $record) {
            $arr = is_array($record) ? $record : $record->toArray();
            $blob .= ($arr['message'] ?? '').' '.json_encode($arr['context'] ?? []).' ';
        }

        $this->assertNotEmpty($handler->getRecords(), 'expected the scan to log a heartbeat');
        $this->assertStringNotContainsString('s3cr3t-pass', $blob);
        $this->assertStringNotContainsString('s3cr3t-community', $blob);
    }

    public function test_logs_when_the_host_count_is_capped(): void
    {
        config(['mymate.discovery.max_hosts_per_subnet' => 2]);
        $subnet = Subnet::factory()->create(['cidr' => '10.80.111.0/24']); // 254 usable, capped to 2
        $this->bindFakes([]); // nothing reachable; we only care about the cap warning

        $handler = $this->captureEngineLog();
        app(ScanSubnet::class)($subnet);

        $this->assertTrue(
            collect($handler->getRecords())->contains(function ($record): bool {
                $arr = is_array($record) ? $record : $record->toArray();

                return str_contains($arr['message'] ?? '', 'capped');
            }),
            'expected a "host count capped" warning'
        );
    }

    /** Route the engine log channel to an in-memory Monolog handler for assertions. */
    private function captureEngineLog(): TestHandler
    {
        $handler = new TestHandler;
        Log::extend('mymate_capture', fn () => new Monolog('mymate', [$handler]));
        config(['logging.channels.mymate_capture' => ['driver' => 'mymate_capture']]);
        config(['mymate.log_channel' => 'mymate_capture']);

        return $handler;
    }
}
