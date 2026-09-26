<?php

namespace Tests\Feature;

use App\Actions\History\HistoryGrid;
use App\Actions\History\HistoryQuery;
use App\Actions\History\ManageHistoryPartitions;
use App\Actions\History\RollupHistory;
use App\Models\Device;
use App\Models\DeviceStorage;
use App\Models\NetworkInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Every history family the device page added goes through the GitHub #28 rollups like the rest. */
class DevicePageRollupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-26 12:34:56'));
        app(ManageHistoryPartitions::class)();
    }

    private function rollup(): void
    {
        app(RollupHistory::class)(0.0);
    }

    /** Read one family back over a 30 day window (hourly tier) with the raw rows gone. */
    private function readHourly(string $family, array $metrics, string $filter, array $bindings, string $rawTable): array
    {
        DB::table($rawTable)->delete();
        $grid = HistoryGrid::build(now()->subDays(30), now());
        $this->assertSame('1h', $grid['tier']);

        return app(HistoryQuery::class)->rows($family, $grid, now(), $metrics, $filter, $bindings);
    }

    public function test_port_rates_and_oper_status_roll_up_on_the_interface_family(): void
    {
        $if = NetworkInterface::factory()->create();
        $rows = [
            ['09:00:00', 1.0, 100.0, true],
            ['09:01:00', 3.0, 300.0, false],
            ['09:02:00', null, 200.0, true],   // a tick that didn't read errors
        ];
        foreach ($rows as [$t, $errors, $pkts, $up]) {
            DB::table('interface_samples')->insert([
                'interface_id' => $if->id, 'ts' => "2026-09-26 {$t}", 'bps_in' => 1000, 'bps_out' => 1000,
                'errors_in' => $errors, 'pkts_in' => $pkts, 'oper_up' => $up,
            ]);
        }

        $this->rollup();

        $five = DB::table('interface_rollup_5m')->where('interface_id', $if->id)->where('bucket', '2026-09-26 09:00:00')->first();
        $this->assertEquals(4.0, $five->errors_in_sum);
        $this->assertSame(2, $five->errors_in_cnt);   // the null tick isn't counted as zero
        $this->assertEquals(3.0, $five->errors_in_max);
        $this->assertEquals(600.0, $five->pkts_in_sum);
        $this->assertEquals(200.0, $five->up_pct_sum); // up, down, up
        $this->assertSame(3, $five->up_pct_cnt);
        $this->assertEquals(0.0, $five->up_pct_min);

        $hour = DB::table('interface_rollup_1h')->where('interface_id', $if->id)->where('bucket', '2026-09-26 09:00:00')->first();
        $this->assertSame(2, $hour->errors_in_cnt);

        $rows = $this->readHourly('interface', ['errors_in' => ['avg', 'max'], 'up_pct' => ['avg', 'min']], 'interface_id = ?', [$if->id], 'interface_samples');
        $b = collect($rows)->firstWhere('bucket', '2026-09-26 09:00:00');
        $this->assertEqualsWithDelta(2.0, $b->errors_in, 0.0001);
        $this->assertEqualsWithDelta(66.667, $b->up_pct, 0.001);
        $this->assertEquals(0.0, $b->up_pct_min);
    }

    public function test_uptime_rolls_up_with_the_min_showing_a_reboot(): void
    {
        $d = Device::factory()->create();
        foreach ([['09:00:00', 100000], ['09:01:00', 100060], ['09:02:00', 30]] as [$t, $up]) {
            DB::table('device_metric_samples')->insert(['device_id' => $d->id, 'ts' => "2026-09-26 {$t}", 'uptime_s' => $up]);
        }

        $this->rollup();

        $hour = DB::table('device_metric_rollup_1h')->where('device_id', $d->id)->where('bucket', '2026-09-26 09:00:00')->first();
        $this->assertEquals(30.0, $hour->uptime_s_min);
        $this->assertEquals(100060.0, $hour->uptime_s_max);
        $this->assertSame(3, $hour->uptime_s_cnt);
    }

    public function test_cpu_family_rolls_up_per_processor(): void
    {
        $d = Device::factory()->create();
        foreach ([['09:00:00', 196608, 10.0], ['09:01:00', 196608, 30.0], ['09:00:00', 196609, 80.0]] as [$t, $cpu, $load]) {
            DB::table('cpu_samples')->insert(['device_id' => $d->id, 'cpu_index' => $cpu, 'ts' => "2026-09-26 {$t}", 'load_pct' => $load]);
        }

        $this->rollup();

        $five = DB::table('cpu_rollup_5m')->where('device_id', $d->id)->where('cpu_index', 196608)->where('bucket', '2026-09-26 09:00:00')->first();
        $this->assertEquals(40.0, $five->load_pct_sum);
        $this->assertSame(2, $five->load_pct_cnt);
        $this->assertEquals(30.0, $five->load_pct_max);
        $this->assertSame(2, DB::table('cpu_rollup_1h')->where('device_id', $d->id)->count()); // one row per processor

        $rows = $this->readHourly('cpu', ['load_pct' => ['avg', 'max']], 'device_id = ?', [$d->id], 'cpu_samples');
        $first = collect($rows)->first(fn ($r) => (int) $r->cpu_index === 196608 && $r->bucket === '2026-09-26 09:00:00');
        $this->assertEqualsWithDelta(20.0, $first->load_pct, 0.0001);
        $this->assertEquals(30.0, $first->load_pct_max);
    }

    public function test_storage_family_rolls_up_per_entry(): void
    {
        $d = Device::factory()->create();
        $s = DeviceStorage::create(['device_id' => $d->id, 'storage_key' => '31', 'descr' => '/', 'type' => 'fixed_disk', 'size_bytes' => 1000]);
        foreach ([['09:00:00', 40.0, 400], ['09:03:00', 60.0, 600]] as [$t, $pct, $used]) {
            DB::table('storage_samples')->insert([
                'storage_id' => $s->id, 'device_id' => $d->id, 'ts' => "2026-09-26 {$t}",
                'used_pct' => $pct, 'used_bytes' => $used, 'size_bytes' => 1000,
            ]);
        }

        $this->rollup();

        $hour = DB::table('storage_rollup_1h')->where('storage_id', $s->id)->where('bucket', '2026-09-26 09:00:00')->first();
        $this->assertEquals(100.0, $hour->used_pct_sum);
        $this->assertEquals(60.0, $hour->used_pct_max);
        $this->assertEquals(600.0, $hour->used_bytes_max);
        $this->assertEquals(1000.0, $hour->size_bytes_max);

        $rows = $this->readHourly('storage', ['used_pct' => ['avg']], 'storage_id = ?', [$s->id], 'storage_samples');
        $this->assertEqualsWithDelta(50.0, collect($rows)->firstWhere('bucket', '2026-09-26 09:00:00')->used_pct, 0.0001);
    }

    public function test_optical_family_rolls_up_with_min_and_max(): void
    {
        $if = NetworkInterface::factory()->create();
        foreach ([['09:00:00', -5.0, -2.0], ['09:01:00', -7.0, -2.2]] as [$t, $rx, $tx]) {
            DB::table('optical_samples')->insert(['interface_id' => $if->id, 'ts' => "2026-09-26 {$t}", 'rx_dbm' => $rx, 'tx_dbm' => $tx]);
        }

        $this->rollup();

        $five = DB::table('optical_rollup_5m')->where('interface_id', $if->id)->where('bucket', '2026-09-26 09:00:00')->first();
        $this->assertEquals(-12.0, $five->rx_dbm_sum);
        $this->assertEquals(-5.0, $five->rx_dbm_max);
        $this->assertEquals(-7.0, $five->rx_dbm_min);
        $this->assertSame(2, $five->tx_dbm_cnt);

        $rows = $this->readHourly('optical', ['rx_dbm' => ['avg', 'min']], 'interface_id = ?', [$if->id], 'optical_samples');
        $b = collect($rows)->firstWhere('bucket', '2026-09-26 09:00:00');
        $this->assertEqualsWithDelta(-6.0, $b->rx_dbm, 0.0001);
        $this->assertEquals(-7.0, $b->rx_dbm_min);
    }

    public function test_rollups_of_the_new_families_are_idempotent(): void
    {
        $d = Device::factory()->create();
        for ($m = 0; $m < 120; $m += 7) {
            DB::table('cpu_samples')->insert([
                'device_id' => $d->id, 'cpu_index' => 1,
                'ts' => now()->subHours(4)->startOfHour()->addMinutes($m)->format('Y-m-d H:i:s'), 'load_pct' => $m / 2,
            ]);
        }

        $this->rollup();
        $first = DB::table('cpu_rollup_5m')->orderBy('bucket')->get()->map(fn ($r) => (array) $r)->all();
        $this->assertNotEmpty($first);

        RollupHistory::rewind(['cpu']);
        $this->rollup();
        $this->assertEquals($first, DB::table('cpu_rollup_5m')->orderBy('bucket')->get()->map(fn ($r) => (array) $r)->all());
    }
}
