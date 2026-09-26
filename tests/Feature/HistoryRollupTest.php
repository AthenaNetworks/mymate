<?php

namespace Tests\Feature;

use App\Actions\History\GetDeviceSamples;
use App\Actions\History\GetInterfaceSamples;
use App\Actions\History\HistoryFamilies;
use App\Actions\History\HistoryGrid;
use App\Actions\History\HistoryTiers;
use App\Actions\History\ManageHistoryPartitions;
use App\Actions\History\RollupHistory;
use App\Models\Device;
use App\Models\Graph;
use App\Models\NetworkInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Long-term history rollups (GitHub #28): the job, the tiers, and the readers on top of them. */
class HistoryRollupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-26 12:34:56'));
        app(ManageHistoryPartitions::class)(); // partitions around the pinned "now"
    }

    private function iface(?Device $device = null): NetworkInterface
    {
        return NetworkInterface::factory()->create(['device_id' => $device ?? Device::factory()]);
    }

    private function sample(int $ifaceId, string $ts, ?float $bpsIn, ?float $utilIn = null): void
    {
        app(ManageHistoryPartitions::class)->ensure('interface_samples', 'day', Carbon::parse($ts));
        DB::table('interface_samples')->insert([
            'interface_id' => $ifaceId, 'ts' => $ts,
            'bps_in' => $bpsIn, 'bps_out' => $bpsIn === null ? null : $bpsIn / 2,
            'util_in' => $utilIn, 'util_out' => null,
        ]);
    }

    private function rollup(): array
    {
        return app(RollupHistory::class)(0.0);
    }

    private function row(string $table, int $ifaceId, string $bucket): ?object
    {
        return DB::table($table)->where('interface_id', $ifaceId)->where('bucket', $bucket)->first();
    }

    /** @return list<array<string,mixed>> */
    private function snapshot(): array
    {
        return collect(['interface_rollup_5m', 'interface_rollup_1h'])
            ->flatMap(fn ($t) => DB::table($t)->orderBy('interface_id')->orderBy('bucket')->get()->map(fn ($r) => [$t => (array) $r]))
            ->all();
    }

    public function test_hourly_average_is_weighted_by_sample_count_and_keeps_the_max(): void
    {
        $if = $this->iface();
        // 09:00 bucket: three samples of 10. 09:10: one of 70. 09:30: a burst, four idle then 500.
        foreach (['09:00:00', '09:01:00', '09:02:00'] as $t) {
            $this->sample($if->id, "2026-09-26 {$t}", 10, 1);
        }
        $this->sample($if->id, '2026-09-26 09:10:00', 70, 7);
        foreach (['09:30:00', '09:30:30', '09:31:00', '09:31:30'] as $t) {
            $this->sample($if->id, "2026-09-26 {$t}", 0, 0);
        }
        $this->sample($if->id, '2026-09-26 09:32:00', 500, 50);

        $this->rollup();

        $five = $this->row('interface_rollup_5m', $if->id, '2026-09-26 09:00:00');
        $this->assertEquals(30.0, $five->bps_in_sum);
        $this->assertSame(3, $five->bps_in_cnt);
        $this->assertEquals(10.0, $five->bps_in_max);

        $hour = $this->row('interface_rollup_1h', $if->id, '2026-09-26 09:00:00');
        $this->assertEquals(600.0, $hour->bps_in_sum);
        $this->assertSame(9, $hour->bps_in_cnt);
        $this->assertEquals(500.0, $hour->bps_in_max); // the burst survives
        $this->assertEquals(50.0, $hour->util_in_max);
        // util_out was never reported: counted as nothing rather than as zeros
        $this->assertSame(0, $hour->util_out_cnt);
        $this->assertNull($hour->util_out_sum);

        // Read it back through a 30 day window (hourly tier) with the raw gone, as if expired.
        DB::table('interface_samples')->delete();
        $data = app(GetInterfaceSamples::class)($if->id, now()->subDays(30), now());
        $bucket = collect($data)->firstWhere('ts', '2026-09-26 09:00:00');
        $this->assertNotNull($bucket);
        // 600 / 9 samples, not (10 + 70 + 100) / 3 buckets = 60
        $this->assertEqualsWithDelta(6.667, $bucket['util_in'], 0.001);
        $this->assertEquals(67.0, $bucket['bps_in']); // bps is rounded to whole bits
    }

    public function test_rerunning_or_rewinding_does_not_change_the_rollups(): void
    {
        $if = $this->iface();
        for ($m = 0; $m < 180; $m += 7) {
            $this->sample($if->id, now()->subHours(4)->startOfHour()->addMinutes($m)->format('Y-m-d H:i:s'), 100 + $m, $m / 10);
        }

        $this->rollup();
        $first = $this->snapshot();
        $this->assertNotEmpty($first);

        $this->rollup();
        $this->assertEquals($first, $this->snapshot());

        // recomputing every closed bucket from scratch replaces rows, it never adds to them
        RollupHistory::rewind();
        $this->rollup();
        $this->assertEquals($first, $this->snapshot());
    }

    public function test_only_closed_buckets_are_rolled_up(): void
    {
        $if = $this->iface();
        $this->sample($if->id, '2026-09-26 12:20:00', 5);  // closed (bucket ended 12:25, grace 300s)
        $this->sample($if->id, '2026-09-26 12:33:00', 50); // still open

        $this->rollup();

        $this->assertNotNull($this->row('interface_rollup_5m', $if->id, '2026-09-26 12:20:00'));
        $this->assertNull($this->row('interface_rollup_5m', $if->id, '2026-09-26 12:30:00'));
        $this->assertSame('2026-09-26 12:25:00', DB::table('history_rollup_state')->where(['family' => 'interface', 'tier' => '5m'])->value('rolled_to'));
        // the 12:00 hour isn't over, so the hourly tier stops at 12:00
        $this->assertSame('2026-09-26 12:00:00', DB::table('history_rollup_state')->where(['family' => 'interface', 'tier' => '1h'])->value('rolled_to'));
    }

    public function test_backfills_existing_raw_in_bounded_runs_and_catches_up(): void
    {
        $if = $this->iface();
        $this->sample($if->id, '2026-09-25 03:00:00', 42);

        $partial = app(RollupHistory::class)(0.000001); // budget runs out after the first slice
        $this->assertFalse($partial['caught_up']);
        $this->assertGreaterThanOrEqual(1, $partial['slices']);

        $this->assertTrue($this->rollup()['caught_up']);
        $this->assertEquals(42.0, $this->row('interface_rollup_5m', $if->id, '2026-09-25 03:00:00')->bps_in_sum);
        $this->assertEquals(42.0, $this->row('interface_rollup_1h', $if->id, '2026-09-25 03:00:00')->bps_in_sum);
    }

    public function test_hourly_tier_is_built_from_raw_where_5m_no_longer_reaches(): void
    {
        // eg an imported history that predates the 5m retention
        config(['mymate.history.retention_days' => 60]);
        $if = $this->iface();
        $this->sample($if->id, '2026-08-10 06:15:00', 8);
        $this->sample($if->id, '2026-08-10 06:45:00', 4);

        $this->rollup();

        $this->assertSame(0, DB::table('interface_rollup_5m')->where('bucket', '<', '2026-08-11')->count());
        $hour = $this->row('interface_rollup_1h', $if->id, '2026-08-10 06:00:00');
        $this->assertEquals(12.0, $hour->bps_in_sum);
        $this->assertSame(2, $hour->bps_in_cnt);
    }

    public function test_every_family_is_rolled_up(): void
    {
        $device = Device::factory()->create();
        DB::table('ping_samples')->insert([
            ['device_id' => $device->id, 'ts' => '2026-09-26 10:00:00', 'rtt_ms' => 10, 'loss_pct' => 0, 'jitter_ms' => 1],
            ['device_id' => $device->id, 'ts' => '2026-09-26 10:01:00', 'rtt_ms' => 30, 'loss_pct' => 100, 'jitter_ms' => 3],
        ]);
        DB::table('device_metric_samples')->insert(['device_id' => $device->id, 'ts' => '2026-09-26 10:00:00', 'cpu_pct' => 40, 'temp_c' => 50]);
        DB::table('sensor_samples')->insert(['sensor_id' => 7, 'device_id' => $device->id, 'ts' => '2026-09-26 10:00:00', 'value' => 3.5]);
        DB::table('probe_samples')->insert([
            ['probe_id' => 9, 'ts' => '2026-09-26 10:00:00', 'up' => true, 'latency_ms' => 20],
            ['probe_id' => 9, 'ts' => '2026-09-26 10:02:00', 'up' => false, 'latency_ms' => null],
        ]);

        $this->rollup();

        $ping = DB::table('ping_rollup_1h')->where('device_id', $device->id)->first();
        $this->assertEquals(20.0, $ping->rtt_ms_sum / $ping->rtt_ms_cnt);
        $this->assertEquals(10.0, $ping->rtt_ms_min);
        $this->assertEquals(30.0, $ping->rtt_ms_max);
        $this->assertEquals(50.0, $ping->loss_pct_sum / $ping->loss_pct_cnt);
        $this->assertEquals(40.0, DB::table('device_metric_rollup_1h')->where('device_id', $device->id)->value('cpu_pct_max'));
        $this->assertEquals(3.5, DB::table('sensor_rollup_1h')->where(['sensor_id' => 7, 'device_id' => $device->id])->value('value_sum'));
        $probe = DB::table('probe_rollup_5m')->where('probe_id', 9)->first();
        $this->assertEquals(50.0, $probe->up_pct_sum / $probe->up_pct_cnt); // one of two checks up
        $this->assertSame(1, $probe->latency_ms_cnt);
    }

    public function test_tier_is_picked_by_window_length_and_retention(): void
    {
        $tiers = app(HistoryTiers::class);
        $now = now();

        $this->assertSame('raw', $tiers->plan($now->copy()->subHour(), $now)['tier']);
        $this->assertSame(15, $tiers->plan($now->copy()->subHour(), $now)['bucketSeconds']);

        $day = $tiers->plan($now->copy()->subDay(), $now);
        $this->assertSame('5m', $day['tier']);
        $this->assertSame(300, $day['bucketSeconds']);
        $this->assertSame('2026-09-25 12:30:00', $day['origin']->format('Y-m-d H:i:s')); // snapped to a 5m edge

        $this->assertSame('5m', $tiers->plan($now->copy()->subDays(7), $now)['tier']);
        $this->assertSame('1h', $tiers->plan($now->copy()->subDays(30), $now)['tier']);
        $year = $tiers->plan($now->copy()->subDays(365), $now);
        $this->assertSame('1h', $year['tier']);
        $this->assertSame(0, $year['bucketSeconds'] % 3600);

        // A short window from before raw retention (14d) comes from 5m, and from before 5m's (30d) from 1h.
        $this->assertSame('5m', $tiers->plan($now->copy()->subDays(20), $now->copy()->subDays(20)->addHours(2))['tier']);
        $this->assertSame('1h', $tiers->plan($now->copy()->subDays(60), $now->copy()->subDays(60)->addHours(2))['tier']);

        // retention is the live setting
        config(['mymate.history.retention_days' => 30]);
        $this->assertSame('raw', $tiers->plan($now->copy()->subDays(20), $now->copy()->subDays(20)->addHours(2))['tier']);
    }

    public function test_a_window_stitches_rollups_and_raw_into_one_weighted_bucket(): void
    {
        $if = $this->iface();
        // Rolled up while it was 10:07: the hour tier closes at 10:00.
        $this->travelTo(Carbon::parse('2026-09-26 10:07:00'));
        $this->sample($if->id, '2026-09-26 09:10:00', 10, 1);
        $this->rollup();
        $this->assertSame('2026-09-26 10:00:00', DB::table('history_rollup_state')->where(['family' => 'interface', 'tier' => '1h'])->value('rolled_to'));
        // the raw behind the watermark is gone, so anything that shows up came from the rollup
        DB::table('interface_samples')->delete();

        // Later, raw that no rollup has seen yet.
        $this->travelTo(Carbon::parse('2026-09-26 11:40:00'));
        foreach (['10:20:00', '10:21:00', '10:22:00'] as $t) {
            $this->sample($if->id, "2026-09-26 {$t}", 100, 10);
        }

        $from = now()->subDays(30);
        $grid = HistoryGrid::build($from, now());
        $this->assertSame('1h', $grid['tier']);
        $this->assertSame(10800, $grid['bucketSeconds']);
        $this->assertArrayHasKey('2026-09-26 08:00:00', $grid['indexOf']); // one bucket spans 08:00-11:00

        $data = app(GetInterfaceSamples::class)($if->id, $from, now());
        $bucket = collect($data)->firstWhere('ts', '2026-09-26 08:00:00');
        // one rolled-up sample of 1 plus three raw of 10: (1 + 30) / 4
        $this->assertEqualsWithDelta(7.75, $bucket['util_in'], 0.001);

        // and the same through a custom graph, on the same grid
        $this->actingAsUser();
        $graph = Graph::create(['name' => 'g', 'config' => ['metric' => 'rate', 'series' => [['interface_id' => $if->id, 'direction' => 'in']]]]);
        $res = $this->getJson("/api/graphs/{$graph->id}/data?range=30d")->assertOk()->json('data');
        $i = array_search('2026-09-26 08:00:00', $res['buckets'], true);
        $this->assertNotFalse($i);
        $this->assertEquals(78.0, $res['series'][0]['values'][$i]); // rate is rounded to whole bps
    }

    public function test_graph_ranges_reach_past_raw_retention(): void
    {
        $this->actingAsUser();
        $if = $this->iface();
        // an hourly row from ~200 days ago, well past raw (14d) and 5m (30d)
        app(ManageHistoryPartitions::class)->ensure('interface_rollup_1h', 'month', Carbon::parse('2026-03-01'));
        DB::table('interface_rollup_1h')->insert([
            'interface_id' => $if->id, 'bucket' => '2026-03-10 05:00:00',
            'bps_in_sum' => 2000, 'bps_in_cnt' => 2, 'bps_in_max' => 1500,
        ]);
        DB::table('history_rollup_state')->insert(['family' => 'interface', 'tier' => '1h', 'rolled_to' => '2026-09-26 12:00:00']);

        $graph = Graph::create(['name' => 'g', 'config' => ['metric' => 'rate', 'series' => [['interface_id' => $if->id, 'direction' => 'in']]]]);
        $res = $this->getJson("/api/graphs/{$graph->id}/data?range=365d")->assertOk()->json('data');

        $this->assertLessThanOrEqual(360, count($res['buckets']));
        $this->assertLessThan('2025-10-01', $res['buckets'][0]); // not capped to raw retention
        $this->assertContains(1000.0, array_map(fn ($v) => $v === null ? null : (float) $v, $res['series'][0]['values']));
    }

    public function test_device_total_sums_each_interface_once_per_bucket(): void
    {
        $device = Device::factory()->create();
        $a = $this->iface($device);
        $b = $this->iface($device);
        // three polls per interface in the same 5m bucket
        foreach (['10:00:00', '10:01:00', '10:02:00'] as $t) {
            $this->sample($a->id, "2026-09-26 {$t}", 100);
            $this->sample($b->id, "2026-09-26 {$t}", 50);
        }

        $data = app(GetDeviceSamples::class)($device->id, now()->subDay(), now());
        $bucket = collect($data)->firstWhere('ts', '2026-09-26 10:00:00');
        $this->assertEquals(150.0, $bucket['bps_in']); // 100 + 50, not 3 x 150

        $this->rollup(); // and the same once it's coming from 5m rollups
        DB::table('interface_samples')->delete();
        $data = app(GetDeviceSamples::class)($device->id, now()->subDay(), now());
        $this->assertEquals(150.0, collect($data)->firstWhere('ts', '2026-09-26 10:00:00')['bps_in']);
    }

    public function test_each_tier_drops_partitions_past_its_own_retention(): void
    {
        $parts = app(ManageHistoryPartitions::class);
        $parts->ensure('interface_rollup_5m', 'day', now()->subDays(40));  // past 30d
        $parts->ensure('interface_rollup_5m', 'day', now()->subDays(10));  // kept
        $parts->ensure('interface_rollup_1h', 'month', now()->subMonths(14)); // past 400d
        $parts->ensure('interface_rollup_1h', 'month', now()->subMonths(2));  // kept
        $parts->ensure('interface_samples', 'day', now()->subDays(20));    // past raw 14d

        $result = $parts();

        $this->assertFalse(Schema::hasTable('interface_rollup_5m_'.now()->subDays(40)->format('Ymd')));
        $this->assertTrue(Schema::hasTable('interface_rollup_5m_'.now()->subDays(10)->format('Ymd')));
        $this->assertFalse(Schema::hasTable('interface_rollup_1h_'.now()->subMonths(14)->format('Ym')));
        $this->assertTrue(Schema::hasTable('interface_rollup_1h_'.now()->subMonths(2)->format('Ym')));
        $this->assertFalse(Schema::hasTable('interface_samples_'.now()->subDays(20)->format('Ymd')));
        $this->assertTrue(Schema::hasTable('interface_rollup_1h_'.now()->format('Ym')));
        $this->assertTrue(Schema::hasTable('interface_rollup_1h_'.now()->addMonthNoOverflow()->format('Ym')));
        $this->assertGreaterThanOrEqual(3, $result['dropped']);

        // the hourly tier's retention is its own setting
        config(['mymate.history.rollup_1h_days' => 20]);
        $parts();
        $this->assertFalse(Schema::hasTable('interface_rollup_1h_'.now()->subMonths(2)->format('Ym')));
        $this->assertTrue(Schema::hasTable('interface_rollup_1h_'.now()->format('Ym')));
    }

    public function test_rollup_command_runs(): void
    {
        $this->artisan('mymate:history:rollup --backfill')->assertSuccessful();
        // every family has a 5m and 1h watermark once it has run, data or not
        $expected = count(HistoryFamilies::FAMILIES) * count(HistoryTiers::ROLLUPS);
        $this->assertSame($expected, DB::table('history_rollup_state')->count());
    }
}
