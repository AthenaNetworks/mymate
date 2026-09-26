<?php

namespace Tests\Feature;

use App\Actions\Agent\DispatchAgentJobs;
use App\Actions\Agent\IngestAgentResults;
use App\Actions\Polling\PollDeviceInterfaces;
use App\Enums\PollMethod;
use App\Events\DeviceMetricsUpdated;
use App\Models\Agent;
use App\Models\Credential;
use App\Models\Device;
use App\Models\NetworkInterface;
use App\Services\Polling\PortStats;
use App\Services\Polling\RateCalculator;
use App\Services\Polling\SnmpThroughputDriver;
use App\Services\Snmp\SnmpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\FakeSnmpClient;
use Tests\TestCase;

/**
 * The 32-bit ifTable fallback for SNMPv1 (airOS and friends) and HC-less ports, centrally and in
 * what we send the agent, plus the wireless RF the agent now reads and how it's stored.
 */
class SnmpFallbackAndAgentWirelessTest extends TestCase
{
    use RefreshDatabase;

    private function snmpDevice(string $version = '2c', array $attrs = []): Device
    {
        $cred = Credential::factory()->create(['snmp_community' => 'public-test', 'snmp_version' => $version]);

        return Device::factory()->create(['poll_method' => PollMethod::Snmp, 'credential_id' => $cred->id, ...$attrs]);
    }

    /** @param list<list<string>> $calls */
    private static function asked(array $calls, string $prefix): bool
    {
        foreach ($calls as $call) {
            foreach ($call as $oid) {
                if (str_starts_with($oid, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    // --- SNMPv1 / HC-less fallback, central -------------------------------------

    public function test_a_v1_device_gets_octets_and_packet_rates_from_the_iftable(): void
    {
        $snmp = new FakeSnmpClient;
        // v1: ifInOctets/ifOutOctets, the HC columns aren't there at all
        $snmp->walks['.1.3.6.1.2.1.2.2.1.10'] = [1 => '704'];
        $snmp->walks['.1.3.6.1.2.1.2.2.1.16'] = [1 => '2000000'];
        $snmp->walks['.1.3.6.1.2.1.2.2.1.8'] = [1 => '1'];
        $snmp->values = [
            '.1.3.6.1.2.1.2.2.1.14.1' => '5',      // ifInErrors
            '.1.3.6.1.2.1.2.2.1.11.1' => '1500',   // ifInUcastPkts
            '.1.3.6.1.2.1.2.2.1.12.1' => '500',    // ifInNUcastPkts
            '.1.3.6.1.2.1.2.2.1.17.1' => '4000',   // ifOutUcastPkts
            '.1.3.6.1.2.1.2.2.1.18.1' => '200',    // ifOutNUcastPkts
        ];
        $this->app->instance(SnmpClient::class, $snmp);

        $device = $this->snmpDevice('1');
        $iface = NetworkInterface::factory()->for($device)->create([
            'if_index' => 1,
            'speed_mbps' => 100,
            // in octets were just under the 32-bit top last time, they've wrapped since
            'last_in' => 4_294_967_296 - 999_296,
            'last_out' => 1_000_000,
            'last_ts' => now()->subSeconds(10),
            // packets last read a minute ago, in was about to wrap
            'port_counters' => ['ts' => microtime(true) - 60, 'c' => ['pkts_in32' => 4_294_966_296, 'pkts_out32' => 1_200]],
        ]);

        $result = app(PollDeviceInterfaces::class)($device);

        $this->assertNotNull($result);
        $row = $result->upsertRows[0];
        // 1,000,000 octets across the wrap in ~10s
        $this->assertEqualsWithDelta(800_000, $row['bps_in'], 80_000);
        $this->assertEqualsWithDelta(800_000, $row['bps_out'], 80_000);
        // 3000 in packets across the wrap and 3000 out, in ~60s
        $h = $result->history[$iface->id];
        $this->assertEqualsWithDelta(50.0, $h['pkts_in'], 1.0);
        $this->assertEqualsWithDelta(50.0, $h['pkts_out'], 1.0);
        $this->assertTrue($h['oper_up']);
        $this->assertSame(2000, json_decode($row['port_counters'], true)['c']['pkts_in32']);
        // and the box was never asked for a Counter64
        $this->assertFalse(self::asked($snmp->getCalls, '.1.3.6.1.2.1.31.'));
    }

    public function test_v1_port_counters_are_one_pass_of_iftable_gets(): void
    {
        $snmp = new FakeSnmpClient;
        $snmp->values = [
            '.1.3.6.1.2.1.2.2.1.11.3' => '4294967000',
            '.1.3.6.1.2.1.2.2.1.12.3' => '1000', // the sum is past 32 bits, kept inside them
            '.1.3.6.1.2.1.2.2.1.17.3' => '10',
        ];

        $c = (new SnmpThroughputDriver($snmp))->portCounters($this->snmpDevice('1'), [3]);

        $this->assertSame(['pkts_in32' => (4_294_967_000 + 1_000) & 0xFFFFFFFF, 'pkts_out32' => 10], $c[3]);
        $this->assertCount(1, $snmp->getCalls);
    }

    public function test_an_hc_less_port_on_v2c_falls_back_to_the_iftable_packets(): void
    {
        $snmp = new FakeSnmpClient;
        $snmp->values = [
            // port 1 has the HC packet counters
            '.1.3.6.1.2.1.31.1.1.1.7.1' => '1000',
            '.1.3.6.1.2.1.31.1.1.1.11.1' => '900',
            '.1.3.6.1.2.1.2.2.1.14.1' => '2',
            // port 2 only has the ifTable ones (and they'd be wrong for port 1 on purpose)
            '.1.3.6.1.2.1.2.2.1.11.1' => '1',
            '.1.3.6.1.2.1.2.2.1.11.2' => '70',
            '.1.3.6.1.2.1.2.2.1.12.2' => '7',
            '.1.3.6.1.2.1.2.2.1.17.2' => '60',
        ];

        $c = (new SnmpThroughputDriver($snmp))->portCounters($this->snmpDevice(), [1, 2]);

        $this->assertSame(['errors_in' => 2, 'pkts_in' => 1000, 'pkts_out' => 900], $c[1]);
        $this->assertSame(['pkts_in32' => 77, 'pkts_out32' => 60], $c[2]);
        // two GETs: the normal one, then the fallback for port 2 only
        $this->assertCount(2, $snmp->getCalls);
        foreach ($snmp->getCalls[1] as $oid) {
            $this->assertStringStartsWith('.1.3.6.1.2.1.2.2.1.1', $oid);
            $this->assertStringEndsWith('.2', $oid);
        }
    }

    public function test_a_v2c_box_with_no_hc_octets_samples_the_32_bit_ones(): void
    {
        $snmp = new FakeSnmpClient;
        $snmp->walks['.1.3.6.1.2.1.2.2.1.10'] = [4 => '100'];
        $snmp->walks['.1.3.6.1.2.1.2.2.1.16'] = [4 => '200'];

        $samples = (new SnmpThroughputDriver($snmp))->sample($this->snmpDevice());

        $this->assertSame(100, $samples[4]->inOctets);
        $this->assertTrue($samples[4]->counter32);

        // a normal HC box is still 64-bit
        $snmp->walks['.1.3.6.1.2.1.31.1.1.1.6'] = [4 => '100'];
        $snmp->walks['.1.3.6.1.2.1.31.1.1.1.10'] = [4 => '200'];
        $this->assertFalse((new SnmpThroughputDriver($snmp))->sample($this->snmpDevice())[4]->counter32);
    }

    public function test_32_bit_wrap_and_width_changes(): void
    {
        $calc = new RateCalculator;

        // octets: 32-bit wraps, 64-bit going backwards is a reset, a reboot to near zero isn't a wrap
        $this->assertEqualsWithDelta(796 * 8 / 10, $calc->bps(4_294_967_000, 500, 10.0, 32), 0.001);
        $this->assertNull($calc->bps(4_294_967_000, 500, 10.0));
        $this->assertNull($calc->bps(3_000_000, 5, 10.0, 32));
        $this->assertNull($calc->bps(9_000_000_000, 5, 10.0, 32)); // last read was an HC value

        // packets: the narrow names always wrap, even without the counter32 list
        $prev = ['ts' => 0.0, 'c' => ['pkts_in32' => 4_294_967_000, 'pkts_out' => 100]];
        $next = PortStats::advance($calc, $prev, ['pkts_in32' => 500, 'pkts_out32' => 150], 10.0);
        $this->assertEqualsWithDelta(79.6, $next['rates']['pkts_in'], 0.001);
        // out switched from 64 to 32-bit since last time: no rate rather than a made up one
        $this->assertNull($next['rates']['pkts_out']);
        $this->assertSame(['pkts_in32' => 500, 'pkts_out32' => 150], $next['state']['c']);
    }

    // --- the agent job ------------------------------------------------------------

    public function test_agent_job_sends_the_iftable_fallback_and_the_wireless_oids(): void
    {
        $agent = Agent::factory()->create();
        $ubnt = $this->snmpDevice('1', ['agent_id' => $agent->id, 'vendor' => 'Ubiquiti', 'monitored' => true]);
        $mt = $this->snmpDevice('2c', ['agent_id' => $agent->id, 'vendor' => 'MikroTik', 'monitored' => true]);
        $plain = $this->snmpDevice('2c', ['agent_id' => $agent->id, 'vendor' => 'Acme', 'monitored' => true]);

        $targets = collect(app(DispatchAgentJobs::class)->buildJob($agent->id)['poll']['snmp'])->keyBy('device_id');

        $ps = $targets[$ubnt->id]['port_stats'];
        $this->assertSame(['.1.3.6.1.2.1.2.2.1.11', '.1.3.6.1.2.1.2.2.1.12'], $ps['fallback']['pkts_in']);
        $this->assertSame(['.1.3.6.1.2.1.2.2.1.17', '.1.3.6.1.2.1.2.2.1.18'], $ps['fallback']['pkts_out']);
        $this->assertSame('1', $targets[$ubnt->id]['snmp']['version']);

        $m = $targets[$ubnt->id]['metrics'];
        $this->assertSame(['.1.3.6.1.4.1.41112.1.4.7.1.3', '.1.3.6.1.4.1.41112.1.4.5.1.5'], $m['signal_walk']);
        $this->assertSame(['.1.3.6.1.4.1.41112.1.4.7.1.6', '.1.3.6.1.4.1.41112.1.4.5.1.7'], $m['ccq_walk']);
        $this->assertSame(['.1.3.6.1.4.1.41112.1.4.5.1.15'], $m['clients_value_walk']);
        $this->assertArrayNotHasKey('snr_walk', $m);

        // a single OID in the profile still goes out as a list
        $m = $targets[$mt->id]['metrics'];
        $this->assertSame(['.1.3.6.1.4.1.14988.1.1.1.2.1.3'], $m['clients_walk']);
        $this->assertSame(['.1.3.6.1.4.1.14988.1.1.1.1.1.4'], $m['signal_oids']);

        $this->assertArrayNotHasKey('signal_oids', $targets[$plain->id]['metrics']);
    }

    // --- agent wireless ingest ------------------------------------------------------

    public function test_agent_wireless_is_stored_like_central_polling_and_broadcast(): void
    {
        Event::fake([DeviceMetricsUpdated::class]);
        $agent = Agent::factory()->create();
        $device = Device::factory()->create(['agent_id' => $agent->id, 'signal_dbm' => -80, 'snr_db' => 12, 'wireless_clients' => 9]);
        $other = Device::factory()->create(['agent_id' => null]);

        app(IngestAgentResults::class)($agent, ['metrics' => [
            [
                'device_id' => $device->id, 'cpu_pct' => 12.0, 'mem_used_pct' => null, 'temp_c' => null, 'storage' => null,
                'signal_dbm' => -61.26, 'snr_db' => null, 'ccq_pct' => 120, 'wireless_clients' => 4,
            ],
            ['device_id' => $other->id, 'cpu_pct' => null, 'mem_used_pct' => null, 'temp_c' => null, 'signal_dbm' => -50, 'wireless_clients' => 1],
        ]]);

        $device->refresh();
        $this->assertEquals(-61.3, $device->signal_dbm);
        $this->assertNull($device->snr_db); // the agent said "no SNR", so the old 12 goes, like central
        $this->assertEquals(100.0, $device->ccq_pct);
        $this->assertSame(4, $device->wireless_clients);
        $this->assertNull($other->fresh()->signal_dbm);

        $sample = DB::table('device_metric_samples')->where('device_id', $device->id)->first();
        $this->assertEquals(-61.3, $sample->signal_dbm);
        $this->assertNull($sample->snr_db);
        $this->assertEquals(100.0, $sample->ccq_pct);
        $this->assertSame(4, (int) $sample->wireless_clients);

        Event::assertDispatched(DeviceMetricsUpdated::class, fn ($e) => count($e->devices) === 1
            && $e->devices[0]['device_id'] === $device->id
            && $e->devices[0]['signal_dbm'] == -61.3
            && $e->devices[0]['snr_db'] === null
            && $e->devices[0]['ccq_pct'] == 100.0
            && $e->devices[0]['wireless_clients'] === 4);
    }

    public function test_rf_alone_counts_as_a_reading(): void
    {
        $agent = Agent::factory()->create();
        $device = Device::factory()->create(['agent_id' => $agent->id]);

        app(IngestAgentResults::class)($agent, ['metrics' => [[
            'device_id' => $device->id, 'cpu_pct' => null, 'mem_used_pct' => null, 'temp_c' => null,
            'signal_dbm' => -70, 'snr_db' => 25, 'ccq_pct' => null, 'wireless_clients' => 1,
        ]]]);

        $this->assertNotNull($device->fresh()->metrics_at);
        $this->assertSame(1, DB::table('device_metric_samples')->where('device_id', $device->id)->count());

        // and an all-null RF frame with nothing else is still nothing
        app(IngestAgentResults::class)($agent, ['metrics' => [[
            'device_id' => $device->id, 'cpu_pct' => null, 'mem_used_pct' => null, 'temp_c' => null,
            'signal_dbm' => null, 'snr_db' => null, 'ccq_pct' => null, 'wireless_clients' => null,
        ]]]);
        $this->assertSame(1, DB::table('device_metric_samples')->where('device_id', $device->id)->count());
        $this->assertEquals(-70, $device->fresh()->signal_dbm);
    }

    public function test_an_older_agent_without_rf_leaves_the_stored_values_alone(): void
    {
        Event::fake([DeviceMetricsUpdated::class]);
        $agent = Agent::factory()->create();
        $device = Device::factory()->create(['agent_id' => $agent->id, 'signal_dbm' => -66, 'wireless_clients' => 3]);

        app(IngestAgentResults::class)($agent, ['metrics' => [
            ['device_id' => $device->id, 'cpu_pct' => 30.0, 'mem_used_pct' => 40.0, 'temp_c' => null],
        ]]);

        $device->refresh();
        $this->assertEquals(-66, $device->signal_dbm);
        $this->assertSame(3, $device->wireless_clients);
        // history doesn't pretend the old value was read now
        $this->assertNull(DB::table('device_metric_samples')->where('device_id', $device->id)->value('signal_dbm'));
        Event::assertDispatched(DeviceMetricsUpdated::class, fn ($e) => $e->devices[0]['signal_dbm'] == -66
            && $e->devices[0]['wireless_clients'] === 3);
    }
}
