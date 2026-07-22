<?php

namespace Tests\Feature;

use App\Enums\PollMethod;
use App\Models\Credential;
use App\Models\Device;
use App\Services\Polling\SnmpThroughputDriver;
use App\Services\Snmp\SnmpClientException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeSnmpClient;
use Tests\TestCase;

class SnmpThroughputDriverTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> */
    private array $oids;

    protected function setUp(): void
    {
        parent::setUp();
        $this->oids = config('mymate.snmp.oids');
    }

    private function snmpDevice(): Device
    {
        $credential = Credential::factory()->create(['snmp_community' => 'public-test']);

        return Device::factory()->create([
            'poll_method' => PollMethod::Snmp,
            'credential_id' => $credential->id,
        ]);
    }

    public function test_discover_maps_names_and_capacity_by_ifindex(): void
    {
        $snmp = new FakeSnmpClient;
        $snmp->walks[$this->oids['if_name']] = [1 => 'ether1', 2 => 'sfp-sfpplus1'];
        $snmp->walks[$this->oids['if_high_speed']] = [1 => '1000', 2 => '10000'];

        $found = (new SnmpThroughputDriver($snmp))->discover($this->snmpDevice());

        $this->assertSame([
            ['if_index' => 1, 'name' => 'ether1', 'description' => null, 'speed_mbps' => 1000],
            ['if_index' => 2, 'name' => 'sfp-sfpplus1', 'description' => null, 'speed_mbps' => 10000],
        ], $found);
    }

    public function test_discover_captures_ifalias_as_description(): void
    {
        $snmp = new FakeSnmpClient;
        $snmp->walks[$this->oids['if_name']] = [1 => 'ether1', 2 => 'ether2'];
        $snmp->walks[$this->oids['if_high_speed']] = [1 => '1000', 2 => '1000'];
        $snmp->walks[$this->oids['if_alias']] = [1 => 'Uplink to BDR1', 2 => '']; // ether2 has no alias

        $found = (new SnmpThroughputDriver($snmp))->discover($this->snmpDevice());

        $this->assertSame('Uplink to BDR1', $found[0]['description']);
        $this->assertNull($found[1]['description']); // empty ifAlias -> null, not ''
    }

    public function test_discover_falls_back_to_ifdescr_when_ifname_empty(): void
    {
        $snmp = new FakeSnmpClient;
        $snmp->walks[$this->oids['if_name']] = []; // agent leaves ifName empty
        $snmp->walks[$this->oids['if_descr']] = [3 => 'Vlan-Trunk'];
        $snmp->walks[$this->oids['if_high_speed']] = []; // unknown capacity

        $found = (new SnmpThroughputDriver($snmp))->discover($this->snmpDevice());

        $this->assertSame([['if_index' => 3, 'name' => 'Vlan-Trunk', 'description' => null, 'speed_mbps' => null]], $found);
    }

    public function test_sample_returns_hc_octet_counters_keyed_by_ifindex(): void
    {
        $snmp = new FakeSnmpClient;
        $snmp->walks[$this->oids['if_hc_in_octets']] = [1 => '111', 2 => '333'];
        $snmp->walks[$this->oids['if_hc_out_octets']] = [1 => '222', 2 => '444'];

        $samples = (new SnmpThroughputDriver($snmp))->sample($this->snmpDevice());

        $this->assertFalse($samples[1]->isDirectRate());
        $this->assertSame(111, $samples[1]->inOctets);
        $this->assertSame(222, $samples[1]->outOctets);
        $this->assertSame(333, $samples[2]->inOctets);
        $this->assertIsFloat($samples[1]->ts);
    }

    public function test_sample_reads_oper_status_up_down_and_leaves_unknown_null(): void
    {
        $snmp = new FakeSnmpClient;
        $snmp->walks[$this->oids['if_hc_in_octets']] = [1 => '111', 2 => '333', 3 => '555'];
        $snmp->walks[$this->oids['if_hc_out_octets']] = [1 => '222', 2 => '444', 3 => '666'];
        // ifOperStatus: 1=up, 2=down; ifIndex 3 not reported.
        $snmp->walks[$this->oids['if_oper_status']] = [1 => '1', 2 => '2'];

        $samples = (new SnmpThroughputDriver($snmp))->sample($this->snmpDevice());

        $this->assertTrue($samples[1]->operUp);
        $this->assertFalse($samples[2]->operUp);
        $this->assertNull($samples[3]->operUp);
    }

    public function test_sample_skips_interfaces_missing_one_direction(): void
    {
        $snmp = new FakeSnmpClient;
        $snmp->walks[$this->oids['if_hc_in_octets']] = [1 => '111', 2 => '333'];
        $snmp->walks[$this->oids['if_hc_out_octets']] = [1 => '222']; // no out for ifIndex 2

        $samples = (new SnmpThroughputDriver($snmp))->sample($this->snmpDevice());

        $this->assertArrayHasKey(1, $samples);
        $this->assertArrayNotHasKey(2, $samples);
    }

    public function test_missing_snmp_community_throws_without_leaking(): void
    {
        $credential = Credential::factory()->routeros()->create(); // no snmp_community
        $device = Device::factory()->create([
            'poll_method' => PollMethod::Snmp,
            'credential_id' => $credential->id,
        ]);

        $this->expectException(SnmpClientException::class);
        (new SnmpThroughputDriver(new FakeSnmpClient))->sample($device);
    }
}
