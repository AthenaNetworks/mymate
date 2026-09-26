<?php

namespace Tests\Feature;

use App\Actions\Agent\DispatchAgentJobs;
use App\Actions\Agent\IngestAgentResults;
use App\Actions\History\ManageHistoryPartitions;
use App\Actions\Polling\PollDeviceInterfaces;
use App\Actions\Polling\PollDeviceMetrics;
use App\Actions\Polling\PollInterfaces;
use App\Actions\Polling\RecordOpticalPower;
use App\Enums\PollMethod;
use App\Models\Agent;
use App\Models\Credential;
use App\Models\Device;
use App\Models\DeviceStorage;
use App\Models\NetworkInterface;
use App\Services\Polling\DeviceMetricProfiles;
use App\Services\Polling\DeviceMetrics;
use App\Services\Polling\DeviceMetricsDriver;
use App\Services\Polling\DeviceMetricsDriverFactory;
use App\Services\Polling\InterfaceSample;
use App\Services\Polling\OpticalReading;
use App\Services\Polling\PortStatsDriver;
use App\Services\Polling\RouterOsDeviceMetricsDriver;
use App\Services\Polling\RouterOsThroughputDriver;
use App\Services\Polling\SnmpDeviceMetricsDriver;
use App\Services\Polling\SnmpThroughputDriver;
use App\Services\Polling\StorageReading;
use App\Services\Polling\ThroughputDriver;
use App\Services\Polling\ThroughputDriverFactory;
use App\Services\Snmp\SnmpClientException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\FakeRouterOsClient;
use Tests\Support\FakeSnmpClient;
use Tests\TestCase;

/**
 * The data the full device page needs that we didn't collect before: port errors / discards /
 * packets and oper status, per-CPU load, storage, uptime, optical history. Central SNMP and
 * RouterOS paths, the remote agent path, and the endpoints.
 */
class DevicePageCollectionTest extends TestCase
{
    use RefreshDatabase;

    private function snmpDevice(string $vendor = 'Acme'): Device
    {
        $cred = Credential::factory()->create(['snmp_community' => 'public-test']);

        return Device::factory()->create(['poll_method' => PollMethod::Snmp, 'credential_id' => $cred->id, 'vendor' => $vendor]);
    }

    private function routerOsDevice(): Device
    {
        $cred = Credential::factory()->routeros()->create();

        return Device::factory()->create(['poll_method' => PollMethod::RouterOs, 'credential_id' => $cred->id]);
    }

    /**
     * Bind a throughput driver that also reads port counters, returning the driver so a test can
     * count its counter reads or make them fail.
     */
    private function fakePortDriver(array $readings, array $counters): object
    {
        $driver = new class($readings, $counters) implements PortStatsDriver, ThroughputDriver
        {
            public int $portCalls = 0;

            public bool $fail = false;

            public function __construct(public array $readings, public array $counters) {}

            public function discover(Device $device): array
            {
                return [];
            }

            public function sample(Device $device): array
            {
                return $this->readings;
            }

            public function portCounters(Device $device, array $ifIndexes): array
            {
                $this->portCalls++;
                if ($this->fail) {
                    throw new SnmpClientException('SNMP get failed: timeout');
                }

                return $this->counters;
            }
        };

        $factory = new class($driver) extends ThroughputDriverFactory
        {
            public function __construct(private ThroughputDriver $driver) {}

            public function for(Device $device): ThroughputDriver
            {
                return $this->driver;
            }
        };
        $this->app->instance(ThroughputDriverFactory::class, $factory);

        return $driver;
    }

    // --- port errors / discards / packets ----------------------------------

    public function test_snmp_port_counters_are_one_get_per_port_set_and_packets_are_summed(): void
    {
        $snmp = new FakeSnmpClient;
        $snmp->getsByCommunity['public-test'] = [
            '.1.3.6.1.2.1.2.2.1.14.1' => '7',        // ifInErrors
            '.1.3.6.1.2.1.2.2.1.20.1' => '1',        // ifOutErrors
            '.1.3.6.1.2.1.31.1.1.1.7.1' => '1000',   // ifHCInUcastPkts
            '.1.3.6.1.2.1.31.1.1.1.8.1' => '20',     // ifHCInMulticastPkts
            '.1.3.6.1.2.1.31.1.1.1.9.1' => '5',      // ifHCInBroadcastPkts
            '.1.3.6.1.2.1.31.1.1.1.11.1' => '900',   // ifHCOutUcastPkts
            // port 2 only answers an out multicast count: no unicast, so no packets for it
            '.1.3.6.1.2.1.31.1.1.1.12.2' => '3',
        ];

        $c = (new SnmpThroughputDriver($snmp))->portCounters($this->snmpDevice(), [1, 2]);

        $this->assertEquals(['errors_in' => 7, 'errors_out' => 1, 'pkts_in' => 1025, 'pkts_out' => 900], $c[1]);
        $this->assertArrayNotHasKey(2, $c);
    }

    public function test_a_device_without_any_port_counter_oids_just_has_none(): void
    {
        // absent OIDs come back as nothing from the client (see PhpSnmpClient::isAbsence)
        $this->assertSame([], (new SnmpThroughputDriver(new FakeSnmpClient))->portCounters($this->snmpDevice(), [1, 2, 3]));
    }

    public function test_port_rates_are_worked_out_from_counter_deltas_with_counter32_wrap(): void
    {
        $device = $this->snmpDevice();
        $iface = NetworkInterface::factory()->for($device)->create([
            'if_index' => 1,
            'port_counters' => ['ts' => microtime(true) - 60, 'c' => ['errors_in' => 4_294_967_000, 'pkts_in' => 1_000, 'discards_in' => 5_000_000_000]],
        ]);
        $this->fakePortDriver(
            [1 => InterfaceSample::counters(1_000, 2_000, microtime(true), false)],
            [1 => ['errors_in' => 500, 'pkts_in' => 7_000, 'discards_in' => 3]],
        );

        $result = app(PollDeviceInterfaces::class)($device);
        $h = $result->history[$iface->id];

        $this->assertEqualsWithDelta(796 / 60, $h['errors_in'], 0.2);  // across the Counter32 wrap
        $this->assertEqualsWithDelta(100.0, $h['pkts_in'], 1.0);      // 6000 in ~60s
        $this->assertNull($h['discards_in']);                          // way past 32 bits: a reset, no spike
        $this->assertNull($h['errors_out']);                           // never read
        $this->assertFalse($h['oper_up']);
        $this->assertEqualsWithDelta(796 / 60, $result->upsertRows[0]['errors_in'], 0.2);
        $this->assertSame(500, json_decode($result->upsertRows[0]['port_counters'], true)['c']['errors_in']);
    }

    public function test_port_stats_run_on_their_own_cadence_and_carry_over_between_reads(): void
    {
        $device = $this->snmpDevice();
        $iface = NetworkInterface::factory()->for($device)->create([
            'if_index' => 1,
            'port_counters' => ['ts' => microtime(true) - 60, 'c' => ['errors_in' => 100]],
        ]);
        $driver = $this->fakePortDriver([1 => InterfaceSample::counters(1_000, 2_000, microtime(true), true)], [1 => ['errors_in' => 160]]);

        app(PollInterfaces::class)([$device->id]);

        $iface->refresh();
        $this->assertSame(1, $driver->portCalls);
        $this->assertEqualsWithDelta(1.0, $iface->errors_in, 0.05);
        $this->assertSame('up', $iface->oper_status);
        $sample = DB::table('interface_samples')->where('interface_id', $iface->id)->first();
        $this->assertEqualsWithDelta(1.0, (float) $sample->errors_in, 0.05);
        $this->assertTrue((bool) $sample->oper_up);

        // straight away again: not due, so no counter read, the live value stays, and no
        // history row claims a fresh error rate
        app(PollInterfaces::class)([$device->id]);

        $this->assertSame(1, $driver->portCalls);
        $this->assertEqualsWithDelta(1.0, $iface->fresh()->errors_in, 0.05);
        $this->assertSame(1, DB::table('interface_samples')->where('interface_id', $iface->id)->whereNotNull('errors_in')->count());
    }

    public function test_a_failed_port_counter_read_never_breaks_the_throughput_tick(): void
    {
        $device = $this->snmpDevice();
        NetworkInterface::factory()->for($device)->create(['if_index' => 1]);
        $driver = $this->fakePortDriver([1 => InterfaceSample::counters(1_000, 2_000, microtime(true), true)], []);
        $driver->fail = true;

        $result = app(PollDeviceInterfaces::class)($device);

        $this->assertNotNull($result);
        $this->assertSame(1_000, $result->upsertRows[0]['last_in']); // the octets still landed
        $this->assertNull($result->upsertRows[0]['errors_in']);
        // and the attempt is remembered so it isn't retried on every tick
        $this->assertNotNull(json_decode($result->upsertRows[0]['port_counters'], true)['ts']);
    }

    public function test_routeros_samples_carry_the_print_counters(): void
    {
        $client = new FakeRouterOsClient(replies: [
            '/interface/print' => [
                ['.id' => '*1', 'name' => 'ether1', 'running' => 'true', 'rx-packet' => '100', 'tx-packet' => '50',
                    'rx-error' => '1', 'tx-error' => '0', 'rx-drop' => '2', 'tx-drop' => '0'],
                ['.id' => '*2', 'name' => 'ether2', 'running' => 'false'],
            ],
            '/interface/monitor-traffic' => [
                ['name' => 'ether1', 'rx-bits-per-second' => '1000', 'tx-bits-per-second' => '500'],
            ],
        ]);

        $samples = (new RouterOsThroughputDriver($client))->sample($this->routerOsDevice());

        $this->assertSame(
            ['pkts_in' => 100, 'pkts_out' => 50, 'errors_in' => 1, 'errors_out' => 0, 'discards_in' => 2, 'discards_out' => 0],
            $samples[1]->counters,
        );
        $this->assertNull($samples[2]->counters); // no stats fields on that row
        $this->assertFalse($samples[2]->operUp);
    }

    // --- per-CPU, storage, uptime ---------------------------------------------

    public function test_snmp_metrics_read_per_cpu_storage_and_uptime(): void
    {
        $hr = config('mymate.device_metrics.hrstorage');
        $snmp = new FakeSnmpClient;
        $snmp->walks['.1.3.6.1.2.1.25.3.3.1.2'] = ['196608' => '10', '196609' => '30'];
        $snmp->walks[$hr['entry']] = [
            '2.1' => '.1.3.6.1.2.1.25.2.1.2', '3.1' => 'Physical memory', '4.1' => '1024', '5.1' => '1000', '6.1' => '250',
            '2.31' => '.1.3.6.1.2.1.25.2.1.4', '3.31' => '/', '4.31' => '4096', '5.31' => '2000', '6.31' => '1500',
        ];
        // hrSystemUptime (host) wins over sysUpTime (the agent)
        $snmp->getsByCommunity['public-test'] = ['.1.3.6.1.2.1.25.1.1.0' => '8640000', '.1.3.6.1.2.1.1.3.0' => '100'];

        $m = (new SnmpDeviceMetricsDriver($snmp, new DeviceMetricProfiles))->sample($this->snmpDevice());

        $this->assertSame(20.0, $m->cpuPct);
        $this->assertSame([196608 => 10.0, 196609 => 30.0], $m->cpuLoads);
        $this->assertSame(25.0, $m->memUsedPct); // from the same entry walk
        $this->assertSame(86400, $m->uptimeSeconds);
        $this->assertCount(2, $m->storages);
        $this->assertSame(2000 * 4096, $m->storages[1]->sizeBytes);
        $this->assertSame(75.0, $m->storages[1]->usedPct());
    }

    public function test_snmp_metrics_fall_back_to_sysuptime_and_absent_oids_stay_null(): void
    {
        $snmp = new FakeSnmpClient;
        $snmp->getsByCommunity['public-test'] = ['.1.3.6.1.2.1.1.3.0' => '(4200) 0:00:42.00'];

        $m = (new SnmpDeviceMetricsDriver($snmp, new DeviceMetricProfiles))->sample($this->snmpDevice());

        $this->assertSame(42, $m->uptimeSeconds);
        $this->assertNull($m->cpuLoads);
        $this->assertSame([], $m->storages); // read fine, nothing there

        // nothing at all answers: still a clean empty reading, not an exception
        $m = (new SnmpDeviceMetricsDriver(new FakeSnmpClient, new DeviceMetricProfiles))->sample($this->snmpDevice());
        $this->assertNull($m->uptimeSeconds);
        $this->assertTrue($m->isEmpty());
    }

    public function test_routeros_metrics_read_per_core_storage_and_uptime(): void
    {
        $client = new FakeRouterOsClient(replies: [
            '/system/resource/print' => [[
                'cpu-load' => '20', 'total-memory' => '1000', 'free-memory' => '750', 'uptime' => '1d2h',
                'total-hdd-space' => '2000', 'free-hdd-space' => '500',
            ]],
            '/system/resource/cpu/print' => [['cpu' => 'cpu0', 'load' => '10'], ['cpu' => 'cpu1', 'load' => '30']],
        ]);

        $m = (new RouterOsDeviceMetricsDriver($client))->sample($this->routerOsDevice());

        $this->assertSame(93600, $m->uptimeSeconds);
        $this->assertSame([0 => 10.0, 1 => 30.0], $m->cpuLoads);
        $this->assertSame(['memory', 'system-disk'], array_map(fn (StorageReading $s) => $s->key, $m->storages));
        $this->assertSame(75.0, $m->storages[1]->usedPct());
    }

    public function test_metrics_tick_records_cpus_storage_and_uptime(): void
    {
        $readings = [
            new DeviceMetrics(cpuPct: 20.0, uptimeSeconds: 3600, cpuLoads: [0 => 10.0, 1 => 30.0], storages: [
                new StorageReading('1', 'Physical memory', 'ram', 1000, 250),
                new StorageReading('31', '/', 'fixed_disk', 2000, 1500),
            ]),
            // next tick: the disk's gone, the box rebooted
            new DeviceMetrics(cpuPct: 20.0, uptimeSeconds: 30, cpuLoads: [0 => 10.0, 1 => 30.0], storages: [
                new StorageReading('1', 'Physical memory', 'ram', 1000, 500),
            ]),
        ];
        $driver = new class($readings) implements DeviceMetricsDriver
        {
            public function __construct(public array $readings) {}

            public function sample(Device $device): DeviceMetrics
            {
                return array_shift($this->readings);
            }
        };
        $this->app->instance(DeviceMetricsDriverFactory::class, new class($driver) extends DeviceMetricsDriverFactory
        {
            public function __construct(private DeviceMetricsDriver $driver) {}

            public function for(Device $device): DeviceMetricsDriver
            {
                return $this->driver;
            }
        });
        $device = $this->snmpDevice();

        app(PollDeviceMetrics::class)([$device->id]);

        $device->refresh();
        $this->assertEquals([['index' => 0, 'load_pct' => 10.0], ['index' => 1, 'load_pct' => 30.0]], $device->cpu_loads);
        $this->assertSame(3600, $device->uptime_seconds);
        $this->assertNotNull($device->uptime_at);
        $this->assertSame(2, DB::table('cpu_samples')->where('device_id', $device->id)->count());
        $this->assertSame(3600, (int) DB::table('device_metric_samples')->where('device_id', $device->id)->value('uptime_s'));
        $this->assertSame(2, DeviceStorage::where('device_id', $device->id)->count());
        $this->assertSame(2, DB::table('storage_samples')->where('device_id', $device->id)->count());
        $disk = DeviceStorage::where('device_id', $device->id)->where('storage_key', '31')->first();
        $this->assertSame(75.0, $disk->used_pct);

        app(PollDeviceMetrics::class)([$device->id]);

        $this->assertSame(['1'], DeviceStorage::where('device_id', $device->id)->pluck('storage_key')->all());
        $this->assertSame(50.0, DeviceStorage::where('device_id', $device->id)->value('used_pct'));
        $this->assertSame(30, $device->fresh()->uptime_seconds);
        $this->assertSame([3600, 30], DB::table('device_metric_samples')->where('device_id', $device->id)->orderByDesc('uptime_s')->pluck('uptime_s')->map(fn ($v) => (int) $v)->all());
    }

    public function test_optical_readings_are_kept_as_history(): void
    {
        $device = $this->snmpDevice();
        $iface = NetworkInterface::factory()->for($device)->create(['if_index' => 5, 'name' => 'sfp1']);

        app(RecordOpticalPower::class)($device->id, [new OpticalReading(ifIndex: 5, name: null, rxDbm: -7.5, txDbm: -2.1)]);

        $row = DB::table('optical_samples')->where('interface_id', $iface->id)->first();
        $this->assertNotNull($row);
        $this->assertEquals(-7.5, $row->rx_dbm);
        $this->assertEquals(-2.1, $row->tx_dbm);
    }

    // --- remote agent -----------------------------------------------------------

    public function test_agent_ingest_takes_port_rates_oper_status_and_the_resource_extras(): void
    {
        $agent = Agent::factory()->create();
        $device = Device::factory()->create(['agent_id' => $agent->id]);
        $iface = NetworkInterface::factory()->create(['device_id' => $device->id, 'speed_mbps' => 1000]);

        app(IngestAgentResults::class)($agent, [
            'throughput' => [[
                'interface_id' => $iface->id, 'in_bps' => 1000, 'out_bps' => 0,
                'oper_up' => false, 'errors_in' => 1.5, 'pkts_in' => 200.0,
            ]],
            'metrics' => [[
                // cpu/mem/temp all unread, the extras alone still count as a reading
                'device_id' => $device->id, 'cpu_pct' => null, 'mem_used_pct' => null, 'temp_c' => null,
                'uptime_s' => 3600,
                'cpus' => [['index' => 196608, 'load_pct' => 10], ['index' => 196609, 'load_pct' => 30]],
                'storage' => [
                    ['key' => '1', 'descr' => 'Physical memory', 'type' => '.1.3.6.1.2.1.25.2.1.2', 'units' => 1024, 'size' => 1000, 'used' => 250],
                    ['key' => '6', 'descr' => 'Memory buffers', 'type' => '.1.3.6.1.2.1.25.2.1.1', 'units' => 1024, 'size' => 1000, 'used' => 10],
                    ['key' => 'system-disk', 'descr' => 'system disk', 'type' => 'flash', 'units' => 1, 'size' => 2048, 'used' => 1024],
                ],
            ]],
        ]);

        $iface->refresh();
        $this->assertEqualsWithDelta(1.5, $iface->errors_in, 0.001);
        $this->assertEqualsWithDelta(200.0, $iface->pkts_in, 0.001);
        $this->assertSame('down', $iface->oper_status);
        $sample = DB::table('interface_samples')->where('interface_id', $iface->id)->first();
        $this->assertEquals(1.5, $sample->errors_in);
        $this->assertNull($sample->errors_out);
        $this->assertFalse((bool) $sample->oper_up);

        $device->refresh();
        $this->assertSame(3600, $device->uptime_seconds);
        $this->assertCount(2, $device->cpu_loads);
        $this->assertSame(2, DB::table('cpu_samples')->where('device_id', $device->id)->count());
        // buffers (type other) are dropped, the RAM is multiplied out of its 1K units
        $this->assertEqualsCanonicalizing(['1', 'system-disk'], DeviceStorage::where('device_id', $device->id)->pluck('storage_key')->all());
        $this->assertSame(1024000, DeviceStorage::where('storage_key', '1')->value('size_bytes'));
        $this->assertSame(2, DB::table('storage_samples')->where('device_id', $device->id)->count());
        $this->assertSame(3600, (int) DB::table('device_metric_samples')->where('device_id', $device->id)->value('uptime_s'));
    }

    public function test_agent_job_asks_for_port_stats_on_the_cadence_and_the_extra_metric_oids(): void
    {
        $agent = Agent::factory()->create();
        $cred = Credential::create(['name' => 'c', 'type' => 'snmp', 'snmp_community' => 'public']);
        $device = Device::factory()->create([
            'agent_id' => $agent->id, 'poll_method' => PollMethod::Snmp, 'credential_id' => $cred->id, 'monitored' => true,
        ]);
        NetworkInterface::factory()->create(['device_id' => $device->id, 'if_index' => 3]);

        $t = app(DispatchAgentJobs::class)->buildJob($agent->id)['poll']['snmp'][0];

        $this->assertSame(
            ['.1.3.6.1.2.1.31.1.1.1.7', '.1.3.6.1.2.1.31.1.1.1.8', '.1.3.6.1.2.1.31.1.1.1.9'],
            $t['port_stats']['columns']['pkts_in'],
        );
        $this->assertSame(['.1.3.6.1.2.1.2.2.1.14'], $t['port_stats']['columns']['errors_in']);
        $this->assertContains('errors_in', $t['port_stats']['counter32']);
        $this->assertSame(config('mymate.device_metrics.hrstorage.entry'), $t['metrics']['hr_entry']);
        $this->assertSame(['.1.3.6.1.2.1.25.1.1.0', '.1.3.6.1.2.1.1.3.0'], $t['metrics']['uptime_oids']);

        // the next job inside the interval doesn't ask again
        $again = app(DispatchAgentJobs::class)->buildJob($agent->id)['poll']['snmp'][0];
        $this->assertNull($again['port_stats']);
    }

    // --- endpoints and plumbing ---------------------------------------------------

    public function test_storage_and_processor_endpoints(): void
    {
        $this->getJson('/api/devices/1/storage')->assertUnauthorized();
        $this->actingAsUser();

        $device = Device::factory()->create([
            'cpu_pct' => 20.0,
            'cpu_loads' => [['index' => 196608, 'load_pct' => 10.0], ['index' => 196609, 'load_pct' => 30.0]],
        ]);
        DeviceStorage::create(['device_id' => $device->id, 'storage_key' => '31', 'descr' => '/', 'type' => 'fixed_disk', 'size_bytes' => 2000, 'used_bytes' => 1500, 'used_pct' => 75.0]);
        DeviceStorage::create(['device_id' => $device->id, 'storage_key' => '1', 'descr' => 'Physical memory', 'type' => 'ram', 'size_bytes' => 1000, 'used_bytes' => 250, 'used_pct' => 25.0]);

        $this->getJson("/api/devices/{$device->id}/storage")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.type', 'ram') // memory first
            ->assertJsonPath('data.1.descr', '/')
            ->assertJsonPath('data.1.used_pct', 75.0);

        $this->getJson("/api/devices/{$device->id}/processors")
            ->assertOk()
            ->assertJsonPath('data.count', 2)
            ->assertJsonPath('data.processors.1.index', 196609)
            ->assertJsonPath('data.cpu_pct', 20.0);
    }

    public function test_new_tables_and_partitions_exist(): void
    {
        $this->assertTrue(Schema::hasColumns('interface_samples', ['pkts_in', 'errors_out', 'discards_in', 'oper_up']));
        $this->assertTrue(Schema::hasColumns('interfaces', ['pkts_in', 'errors_in', 'port_counters']));
        $this->assertTrue(Schema::hasColumns('interface_rollup_5m', ['errors_in_sum', 'errors_in_cnt', 'errors_in_max', 'up_pct_min']));
        $this->assertTrue(Schema::hasColumns('device_metric_rollup_1h', ['uptime_s_sum', 'uptime_s_min', 'uptime_s_max']));

        app(ManageHistoryPartitions::class)();
        foreach (['cpu_samples', 'storage_samples', 'optical_samples', 'cpu_rollup_5m'] as $t) {
            $this->assertTrue(Schema::hasTable($t.'_'.now()->format('Ymd')), "{$t} has no partition for today");
        }
        $this->assertTrue(Schema::hasTable('storage_rollup_1h_'.now()->format('Ym')));
    }
}
