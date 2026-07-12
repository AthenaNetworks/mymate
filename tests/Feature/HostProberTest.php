<?php

namespace Tests\Feature;

use App\Enums\PollMethod;
use App\Models\Credential;
use App\Services\Discovery\HostProber;
use App\Services\RouterOs\RouterOsClientException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeRouterOsClient;
use Tests\Support\FakeSnmpClient;
use Tests\TestCase;

class HostProberTest extends TestCase
{
    use RefreshDatabase;

    private function sysNameOid(): string
    {
        return (string) config('mymate.snmp.oids.sys_name');
    }

    private function prober(FakeSnmpClient $snmp, FakeRouterOsClient $routerOs): HostProber
    {
        return new HostProber($snmp, $routerOs, attemptDelayMs: 0);
    }

    public function test_matches_an_snmp_community_from_the_pool_and_records_it(): void
    {
        $wrong = Credential::factory()->create(['type' => 'snmp', 'snmp_community' => 'wrong']);
        $right = Credential::factory()->create(['type' => 'snmp', 'snmp_community' => 'public']);

        $snmp = new FakeSnmpClient;
        $snmp->throwOnUnknownGet = true; // 'wrong' (and any other) times out
        $snmp->getsByCommunity['public'] = [$this->sysNameOid() => 'core-rtr-1'];

        $result = $this->prober($snmp, new FakeRouterOsClient)
            ->probe('10.0.0.1', Credential::all());

        $this->assertSame(PollMethod::Snmp, $result->method);
        $this->assertSame('core-rtr-1', $result->sysname);
        $this->assertSame($right->id, $result->credentialId);
        $this->assertNotSame($wrong->id, $result->credentialId);
    }

    public function test_falls_back_to_routeros_login_when_snmp_does_not_match(): void
    {
        Credential::factory()->create(['type' => 'snmp', 'snmp_community' => 'nope']);
        $api = Credential::factory()->routeros()->create();

        $snmp = new FakeSnmpClient;
        $snmp->throwOnUnknownGet = true; // SNMP never matches

        $routerOs = new FakeRouterOsClient(replies: [
            '/system/identity/print' => [['name' => 'RB-EDGE']],
        ]);

        $result = $this->prober($snmp, $routerOs)->probe('10.0.0.2', Credential::all());

        $this->assertSame(PollMethod::RouterOs, $result->method);
        $this->assertSame('RB-EDGE', $result->sysname);
        $this->assertSame($api->id, $result->credentialId);
    }

    public function test_returns_none_when_no_credential_matches(): void
    {
        Credential::factory()->create(['type' => 'snmp', 'snmp_community' => 'nope']);
        Credential::factory()->routeros()->create();

        $snmp = new FakeSnmpClient;
        $snmp->throwOnUnknownGet = true;
        $routerOs = new FakeRouterOsClient(failOpenWith: new RouterOsClientException('connect failed'));

        $result = $this->prober($snmp, $routerOs)->probe('10.0.0.3', Credential::all());

        $this->assertFalse($result->identified());
        $this->assertNull($result->method);
        $this->assertNull($result->sysname);
        $this->assertNull($result->credentialId);
    }

    public function test_skips_credentials_with_no_secret(): void
    {
        // An SNMP cred with a blank community and a RouterOS cred with no username
        // are both skipped (never even attempted) -> none, without error.
        Credential::factory()->create(['type' => 'snmp', 'snmp_community' => '']);
        Credential::factory()->create(['type' => 'routeros', 'username' => null, 'password' => null]);

        $result = $this->prober(new FakeSnmpClient, new FakeRouterOsClient)
            ->probe('10.0.0.4', Credential::all());

        $this->assertFalse($result->identified());
    }
}
