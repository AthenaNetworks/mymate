<?php

namespace Tests\Feature;

use App\Actions\History\ManageHistoryPartitions;
use App\Models\AlertEvent;
use App\Models\AlertPolicy;
use App\Models\Device;
use App\Models\DeviceMapPosition;
use App\Models\Map;
use App\Models\NetworkInterface;
use App\Models\Outage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The device page's history, catalog, billing and events endpoints (GitHub #28). */
class DeviceHistoryApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-26 12:34:56'));
        app(ManageHistoryPartitions::class)();
    }

    private function sample(int $ifaceId, string $ts, ?float $in, ?float $out = null): void
    {
        app(ManageHistoryPartitions::class)->ensure('interface_samples', 'day', Carbon::parse($ts));
        DB::table('interface_samples')->insert([
            'interface_id' => $ifaceId, 'ts' => $ts, 'bps_in' => $in, 'bps_out' => $out, 'util_in' => null, 'util_out' => null,
        ]);
    }

    private function hourly(int $ifaceId, string $bucket, float $sum, int $cnt, float $max): void
    {
        app(ManageHistoryPartitions::class)->ensure('interface_rollup_1h', 'month', Carbon::parse($bucket));
        DB::table('interface_rollup_1h')->insert([
            'interface_id' => $ifaceId, 'bucket' => $bucket,
            'bps_in_sum' => $sum, 'bps_in_cnt' => $cnt, 'bps_in_max' => $max,
            'bps_out_sum' => null, 'bps_out_cnt' => 0, 'bps_out_max' => null,
            'util_in_sum' => null, 'util_in_cnt' => 0, 'util_in_max' => null,
            'util_out_sum' => null, 'util_out_cnt' => 0, 'util_out_max' => null,
        ]);
    }

    private function watermark(string $family, string $tier, string $at): void
    {
        DB::table('history_rollup_state')->updateOrInsert(['family' => $family, 'tier' => $tier], ['rolled_to' => $at]);
    }

    /** Nearest-rank 95th percentile, the reference the SQL has to agree with. */
    private static function p95(array $values): float
    {
        sort($values);

        return $values[(int) ceil(0.95 * count($values)) - 1];
    }

    public function test_long_ranges_read_the_hourly_tier_and_short_ones_raw(): void
    {
        $this->actingAsUser();
        $if = NetworkInterface::factory()->create();
        $this->hourly($if->id, '2026-08-01 10:00:00', 1200, 12, 400);
        $this->watermark('interface', '1h', '2026-09-26 12:00:00');
        $this->watermark('interface', '5m', '2026-09-26 12:30:00');
        $this->sample($if->id, '2026-09-26 12:20:00', 50, 5);

        $long = $this->getJson("/api/devices/{$if->device_id}/history?family=interface&metrics[]=bps_in&from=".urlencode(now()->subDays(90)->toIso8601String()))
            ->assertOk()->json('data');
        $this->assertSame('1h', $long['tier']);
        $this->assertSame(0, $long['step'] % 3600);
        $series = collect($long['series'])->firstWhere('key', (string) $if->id);
        $this->assertSame('bps_in', $series['metric']);
        $this->assertSame('bps', $series['unit']);
        // the rollup row averages by sample count, and keeps its burst in the max column
        $this->assertTrue(in_array(100, $series['avg']));
        $this->assertEquals(400.0, $series['stats']['peak']);

        $short = $this->getJson("/api/devices/{$if->device_id}/history?family=interface&from=".urlencode(now()->subHour()->toIso8601String()))
            ->assertOk()->json('data');
        $this->assertSame('raw', $short['tier']);
        $this->assertEquals(50.0, collect($short['series'])->firstWhere('metric', 'bps_in')['stats']['last']);
    }

    public function test_points_controls_the_grid_density(): void
    {
        $this->actingAsUser();
        $if = NetworkInterface::factory()->create();
        $from = urlencode(now()->subDay()->toIso8601String());

        $a = $this->getJson("/api/devices/{$if->device_id}/history?family=interface&from={$from}&points=100")->json('data');
        $b = $this->getJson("/api/devices/{$if->device_id}/history?family=interface&from={$from}&points=500")->json('data');
        $this->assertLessThanOrEqual(110, $a['count']);
        $this->assertGreaterThan($a['count'], $b['count']);
    }

    public function test_keys_are_scoped_to_the_device(): void
    {
        $this->actingAsUser();
        $mine = NetworkInterface::factory()->create();
        $theirs = NetworkInterface::factory()->create();
        $this->sample($mine->id, '2026-09-26 12:00:00', 10, 1);
        $this->sample($theirs->id, '2026-09-26 12:00:00', 999, 999);
        $url = "/api/devices/{$mine->device_id}/history?family=interface&from=".urlencode(now()->subHour()->toIso8601String());

        // another device's interface through this device's URL is refused outright
        $this->getJson("{$url}&keys[]={$theirs->id}")->assertStatus(422);
        $this->getJson("{$url}&keys[]={$mine->id}&keys[]={$theirs->id}")->assertStatus(422);
        $this->getJson("{$url}&keys[]=abc")->assertStatus(422);

        // and with no keys only this device's ports come back
        $keys = collect($this->getJson($url)->assertOk()->json('data.series'))->pluck('key')->unique()->values()->all();
        $this->assertSame([(string) $mine->id], $keys);

        // the device total only sums this device's ports
        $total = $this->getJson("{$url}&aggregate=1&metrics[]=bps_in")->assertOk()->json('data.series.0');
        $this->assertNull($total['key']);
        $this->assertEquals(10.0, $total['stats']['max']);

        $this->getJson("{$url}&metrics[]=nope")->assertStatus(422);
        $this->getJson("/api/devices/{$mine->device_id}/history?family=nope")->assertStatus(422);
    }

    public function test_restricted_operator_cannot_reach_a_hidden_device(): void
    {
        $mapA = Map::factory()->create();
        $mapB = Map::factory()->create();
        $ifA = NetworkInterface::factory()->create();
        $ifB = NetworkInterface::factory()->create();
        DeviceMapPosition::create(['device_id' => $ifA->device_id, 'map_id' => $mapA->id, 'x' => 0, 'y' => 0]);
        DeviceMapPosition::create(['device_id' => $ifB->device_id, 'map_id' => $mapB->id, 'x' => 0, 'y' => 0]);
        $user = User::factory()->create(['is_admin' => false, 'restricted' => true]);
        $user->maps()->attach($mapA->id);
        $this->actingAs($user);

        foreach (['history?family=interface', 'history/catalog', 'billing', 'events', 'summary'] as $path) {
            $this->getJson("/api/devices/{$ifB->device_id}/{$path}")->assertNotFound();
            $this->getJson("/api/devices/{$ifA->device_id}/{$path}")->assertOk();
        }
        // a visible device can't be used as a window onto the hidden one's port
        $this->getJson("/api/devices/{$ifA->device_id}/history?family=interface&keys[]={$ifB->id}")->assertStatus(422);
        $this->getJson("/api/devices/{$ifA->device_id}/billing?keys[]={$ifB->id}")->assertStatus(422);

        // the summary only lists maps this operator can see
        DeviceMapPosition::create(['device_id' => $ifA->device_id, 'map_id' => $mapB->id, 'x' => 0, 'y' => 0]);
        $maps = $this->getJson("/api/devices/{$ifA->device_id}/summary")->json('data.maps');
        $this->assertSame([$mapA->id], array_column($maps, 'id'));
    }

    public function test_stats_and_p95_on_a_known_dataset(): void
    {
        $this->actingAsUser();
        $if = NetworkInterface::factory()->create();
        $start = Carbon::parse('2026-09-26 03:00:00');
        $in = [];
        // 100 five minute intervals, one sample each, 1k..100k shuffled so order can't fake it
        $order = range(1, 100);
        mt_srand(7);
        shuffle($order);
        foreach ($order as $i => $n) {
            $this->sample($if->id, $start->copy()->addMinutes(5 * $i)->format('Y-m-d H:i:s'), $n * 1000.0, 1.0);
            $in[] = $n * 1000.0;
        }
        $last = end($in);

        $data = $this->getJson("/api/devices/{$if->device_id}/history?family=interface&metrics[]=bps_in&p95=1&points=400&from="
            .urlencode($start->toIso8601String()).'&to='.urlencode($start->copy()->addMinutes(500)->toIso8601String()))
            ->assertOk()->json('data');

        $stats = $data['series'][0]['stats'];
        $this->assertEquals(1000.0, $stats['min']);
        $this->assertEquals(100000.0, $stats['max']);
        $this->assertEqualsWithDelta(50500.0, $stats['avg'], 0.01);
        $this->assertEquals($last, $stats['last']);
        $this->assertEquals(95000.0, $stats['p95']);
        $this->assertEquals(self::p95($in), $stats['p95']);
        $this->assertSame('5m', $data['p95_resolution']);
    }

    public function test_catalog_lists_only_what_has_data_with_labels_and_units(): void
    {
        $this->actingAsUser();
        $device = Device::factory()->create();
        $used = NetworkInterface::factory()->create(['device_id' => $device->id, 'name' => 'ether1']);
        NetworkInterface::factory()->create(['device_id' => $device->id, 'name' => 'ether2']); // never sampled
        $other = NetworkInterface::factory()->create(['name' => 'not-mine']);
        $this->sample($used->id, '2026-09-26 12:00:00', 10, 20);
        $this->sample($other->id, '2026-09-26 12:00:00', 10, 20);
        app(ManageHistoryPartitions::class)->ensure('device_metric_samples', 'day', now());
        DB::table('device_metric_samples')->insert(['device_id' => $device->id, 'ts' => '2026-09-26 12:00:00', 'cpu_pct' => 12.5]);
        // a device_metric row from months back that only survives in the hourly rollup
        app(ManageHistoryPartitions::class)->ensure('device_metric_rollup_1h', 'month', Carbon::parse('2026-06-01'));
        DB::table('device_metric_rollup_1h')->insert(['device_id' => $device->id, 'bucket' => '2026-06-01 00:00:00', 'temp_c_sum' => 40, 'temp_c_cnt' => 1]);

        $data = $this->getJson("/api/devices/{$device->id}/history/catalog")->assertOk()->json('data');
        $families = collect($data['families'])->keyBy('family');

        $this->assertEqualsCanonicalizing(['interface', 'device_metric'], $families->keys()->all());
        $iface = $families['interface'];
        $this->assertTrue($iface['keyed']);
        $this->assertSame([['key' => (string) $used->id, 'label' => 'ether1', 'description' => $used->description]], $iface['keys']);
        $this->assertEqualsCanonicalizing(['bps_in', 'bps_out'], array_column($iface['metrics'], 'metric'));
        $bpsIn = collect($iface['metrics'])->firstWhere('metric', 'bps_in');
        $this->assertSame(['metric' => 'bps_in', 'label' => 'In', 'unit' => 'bps', 'group' => 'traffic', 'aggs' => ['avg', 'max']], $bpsIn);

        $health = collect($families['device_metric']['metrics'])->keyBy('metric');
        $this->assertEqualsCanonicalizing(['cpu_pct', 'temp_c'], $health->keys()->all());
        $this->assertSame('cpu', $health['cpu_pct']['group']);
        $this->assertSame('%', $health['cpu_pct']['unit']);
        $this->assertNull($families['device_metric']['keys']);
        $this->assertArrayHasKey('1h', $data['retention_days']);
    }

    public function test_billing_p95_and_transfer_on_a_crafted_dataset(): void
    {
        $this->actingAsUser();
        $device = Device::factory()->create();
        $a = NetworkInterface::factory()->create(['device_id' => $device->id]);
        $b = NetworkInterface::factory()->create(['device_id' => $device->id]);
        $start = Carbon::parse('2026-09-25 00:00:00');
        $in = $out = $max = $sumIn = [];
        for ($i = 1; $i <= 100; $i++) {
            $ts = $start->copy()->addMinutes(5 * ($i - 1));
            // two polls inside the interval, averaging to the value we want to bill on
            $vi = $i * 1000.0;
            $vo = (101 - $i) * 500.0;
            $this->sample($a->id, $ts->format('Y-m-d H:i:s'), $vi - 100, $vo - 50);
            $this->sample($a->id, $ts->copy()->addMinutes(2)->format('Y-m-d H:i:s'), $vi + 100, $vo + 50);
            $this->sample($b->id, $ts->format('Y-m-d H:i:s'), 10.0, 10.0);
            $in[] = $vi;
            $out[] = $vo;
            $max[] = max($vi, $vo);
            $sumIn[] = $vi + 10.0;
        }

        $url = "/api/devices/{$device->id}/billing?period=custom&from=".urlencode($start->toIso8601String()).'&to='.urlencode($start->copy()->addDay()->toIso8601String());

        $one = $this->getJson("{$url}&keys[]={$a->id}")->assertOk()->json('data');
        $this->assertSame('5m', $one['resolution']);
        $this->assertTrue($one['precise']);
        $this->assertNull($one['aggregate']);
        $port = $one['ports'][0];
        $this->assertSame((string) $a->id, $port['key']);
        $this->assertSame(100, $port['samples']);
        $this->assertEquals(self::p95($in), $port['p95_in']);
        $this->assertEquals(95000.0, $port['p95_in']);
        $this->assertEquals(self::p95($out), $port['p95_out']);
        $this->assertEquals(self::p95($max), $port['p95_max']);
        // bytes = average rate x 300 s / 8 per interval
        $this->assertEqualsWithDelta(array_sum($in) * 300 / 8, $port['bytes_in'], 1);
        $this->assertEqualsWithDelta(array_sum($out) * 300 / 8, $port['bytes_out'], 1);

        // both ports: summed per interval first, then the percentile of the sum
        $both = $this->getJson("{$url}&keys[]={$a->id}&keys[]={$b->id}")->assertOk()->json('data');
        $this->assertCount(2, $both['ports']);
        $this->assertEquals(self::p95($sumIn), $both['aggregate']['p95_in']);
    }

    public function test_billing_month_periods_split_on_the_boundary(): void
    {
        // keep 5 minute rollups long enough to cover last month, so it stays precise
        config(['mymate.history.rollup_5m_days' => 90]);
        $this->actingAsUser();
        $if = NetworkInterface::factory()->create();
        $this->sample($if->id, '2026-07-31 23:55:00', 7777, 1);   // July, in neither
        $this->sample($if->id, '2026-08-01 00:00:00', 2000, 1);   // first interval of August
        $this->sample($if->id, '2026-08-31 23:55:00', 1000, 1);   // last interval of August
        $this->sample($if->id, '2026-09-01 00:00:00', 9999, 1);   // first interval of September

        $last = $this->getJson("/api/devices/{$if->device_id}/billing?period=last_month&keys[]={$if->id}")->assertOk()->json('data');
        $this->assertSame('last_month', $last['period']);
        $this->assertSame('2026-08-01T00:00:00Z', $last['from']);
        $this->assertSame('2026-09-01T00:00:00Z', $last['to']);
        $this->assertTrue($last['precise']);
        $this->assertSame(2, $last['ports'][0]['samples']);
        $this->assertEquals(2000.0, $last['ports'][0]['p95_in']);
        $this->assertSame(8928, $last['expected_samples']); // 31 days of 5 minute intervals

        $this->travelTo(Carbon::parse('2026-09-01 00:10:00'));
        $this->sample($if->id, '2026-09-01 00:05:00', 3333, 1);
        $this->travelTo(Carbon::parse('2026-09-26 12:34:56'));
        $this->sample($if->id, '2026-09-26 12:00:00', 1, 1); // today

        $this_ = $this->getJson("/api/devices/{$if->device_id}/billing?period=this_month&keys[]={$if->id}")->assertOk()->json('data');
        $this->assertSame('2026-09-01T00:00:00Z', $this_['from']);
        $this->assertSame(3, $this_['ports'][0]['samples']);
        $this->assertEquals(9999.0, $this_['ports'][0]['p95_in']);
    }

    public function test_billing_falls_back_to_hourly_beyond_the_5m_retention(): void
    {
        $this->actingAsUser();
        $if = NetworkInterface::factory()->create();
        // last month starts before the default 30 day 5m retention (cutoff 2026-08-27)
        $this->sample($if->id, '2026-08-10 06:00:00', 100, 1);
        $this->sample($if->id, '2026-08-10 06:30:00', 300, 1);

        $data = $this->getJson("/api/devices/{$if->device_id}/billing?period=last_month")->assertOk()->json('data');
        $this->assertSame('1h', $data['resolution']);
        $this->assertFalse($data['precise']);
        $this->assertSame(3600, $data['step']);
        // one hourly sample, the average of the two polls
        $this->assertSame(1, $data['ports'][0]['samples']);
        $this->assertEquals(200.0, $data['ports'][0]['p95_in']);

        $this->getJson("/api/devices/{$if->device_id}/billing?period=this_month")->assertOk()->assertJsonPath('data.precise', true);
        $this->getJson("/api/devices/{$if->device_id}/billing?period=custom")->assertStatus(422);
    }

    public function test_events_merge_outages_and_alerts_newest_first_and_page(): void
    {
        $this->actingAsUser();
        $device = Device::factory()->create(['uptime_seconds' => 3600, 'uptime_at' => '2026-09-26 12:00:00']);
        $other = Device::factory()->create();
        Outage::create(['device_id' => $device->id, 'started_at' => '2026-09-20 10:00:00', 'ended_at' => '2026-09-20 10:05:00', 'duration_s' => 300]);
        Outage::create(['device_id' => $other->id, 'started_at' => '2026-09-21 10:00:00']);
        $policy = AlertPolicy::factory()->create(['name' => 'CPU high']);
        AlertEvent::create(['alert_policy_id' => $policy->id, 'dedupe_key' => "device:{$device->id}:metric:cpu", 'status' => 'resolved', 'message' => 'High cpu', 'fired_at' => '2026-09-22 08:00:00', 'resolved_at' => '2026-09-22 09:00:00']);
        // device 1 vs device 1x: the key prefix must not bleed across ids
        AlertEvent::create(['alert_policy_id' => $policy->id, 'dedupe_key' => "device:{$device->id}9", 'status' => 'firing', 'message' => 'Not mine', 'fired_at' => '2026-09-23 08:00:00']);

        $res = $this->getJson("/api/devices/{$device->id}/events?per_page=5")->assertOk()->json();
        $titles = array_column($res['data'], 'title');
        $this->assertSame(['Last boot', 'Alert resolved: CPU high', 'Alert fired: CPU high', 'Came back up', 'Went down'], $titles);
        $this->assertSame(5, $res['meta']['total']);
        $this->assertFalse($res['meta']['has_more']);
        $this->assertSame('2026-09-26T11:00:00Z', $res['data'][0]['at']);

        $page = $this->getJson("/api/devices/{$device->id}/events?per_page=5&page=1&types[]=outage")->json();
        $this->assertSame(['outage', 'outage'], array_column($page['data'], 'type'));
    }
}
