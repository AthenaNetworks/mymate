<?php

namespace Tests\Feature;

use App\Actions\History\ManageHistoryPartitions;
use App\Actions\History\RollupHistory;
use App\Models\Device;
use App\Models\DeviceMapPosition;
use App\Models\Link;
use App\Models\Map;
use App\Models\NetworkInterface;
use App\Models\Outage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Geo map playback (GitHub #22): frames of link bps and device state for one map. */
class MapPlaybackTest extends TestCase
{
    use RefreshDatabase;

    private Map $map;

    private Device $a;

    private Device $b;

    private NetworkInterface $ifA;

    private NetworkInterface $ifB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-26 12:34:56'));
        app(ManageHistoryPartitions::class)();

        // two devices on the map joined by a link, a third off the map linked to one of them
        $this->map = Map::factory()->create(['name' => 'Region A']);
        $this->a = Device::factory()->create(['name' => 'a']);
        $this->b = Device::factory()->create(['name' => 'b']);
        foreach ([$this->a, $this->b] as $d) {
            DeviceMapPosition::create(['device_id' => $d->id, 'map_id' => $this->map->id, 'x' => 0, 'y' => 0]);
        }
        $this->ifA = NetworkInterface::factory()->create(['device_id' => $this->a->id]);
        $this->ifB = NetworkInterface::factory()->create(['device_id' => $this->b->id]);
        Link::create(['a_device_id' => $this->a->id, 'a_interface_id' => $this->ifA->id, 'b_device_id' => $this->b->id, 'b_interface_id' => $this->ifB->id]);
    }

    private function url(string $query): string
    {
        return "/api/maps/{$this->map->id}/playback?{$query}";
    }

    private function bps(int $ifaceId, string $ts, float $in, float $out): void
    {
        app(ManageHistoryPartitions::class)->ensure('interface_samples', 'day', Carbon::parse($ts));
        DB::table('interface_samples')->insert(['interface_id' => $ifaceId, 'ts' => $ts, 'bps_in' => $in, 'bps_out' => $out]);
    }

    private function ping(int $deviceId, string $ts, float $rtt, float $loss): void
    {
        app(ManageHistoryPartitions::class)->ensure('ping_samples', 'day', Carbon::parse($ts));
        DB::table('ping_samples')->insert(['device_id' => $deviceId, 'ts' => $ts, 'rtt_ms' => $rtt, 'loss_pct' => $loss]);
    }

    private function frameOf(array $data, string $ts): int
    {
        $i = array_search(Carbon::parse($ts)->getTimestamp(), $data['frames'], true);
        $this->assertNotFalse($i, "no frame at {$ts}");

        return $i;
    }

    public function test_frames_average_samples_and_line_up_on_one_axis(): void
    {
        $this->actingAsUser();
        // 11:00-12:00 in 5 minute frames. Two polls in the 11:05 frame, one in 11:20.
        $this->bps($this->ifA->id, '2026-09-26 11:05:00', 1_000_000, 200);
        $this->bps($this->ifA->id, '2026-09-26 11:06:00', 3_000_000, 400);
        $this->bps($this->ifB->id, '2026-09-26 11:20:30', 5, 7);
        $this->ping($this->a->id, '2026-09-26 11:05:10', 10, 0);
        $this->ping($this->a->id, '2026-09-26 11:06:10', 20, 100);

        $data = $this->getJson($this->url('from=2026-09-26T11:00:00Z&to=2026-09-26T12:00:00Z&points=12'))
            ->assertOk()->json('data');

        $this->assertSame('range', $data['mode']);
        $this->assertSame(300, $data['step']);
        $this->assertCount(12, $data['frames']);
        $this->assertSame(Carbon::parse('2026-09-26 11:00:00')->getTimestamp(), $data['frames'][0]);
        $this->assertEqualsCanonicalizing([$this->a->id, $this->b->id], $data['device_ids']);

        $in = $data['interfaces'][$this->ifA->id]['bps_in'];
        $this->assertCount(12, $in);
        $this->assertSame(2_000_000, $in[$this->frameOf($data, '2026-09-26 11:05:00')]);
        $this->assertSame(300, $data['interfaces'][$this->ifA->id]['bps_out'][1]);
        $this->assertNull($in[0]);
        $this->assertSame(5, $data['interfaces'][$this->ifB->id]['bps_in'][4]);

        $this->assertEquals(15.0, $data['devices'][$this->a->id]['rtt_ms'][1]);
        $this->assertEquals(50.0, $data['devices'][$this->a->id]['loss_pct'][1]);
        // b never answered a ping in the window, so it has no series at all
        $this->assertArrayNotHasKey((string) $this->b->id, $data['devices']);
        $this->assertEquals([], $data['down']);
    }

    public function test_status_comes_from_outages_including_short_and_open_ones(): void
    {
        $this->actingAsUser();
        // a 40 second blip inside the 11:05 frame, then down from 11:52 and still down
        Outage::create(['device_id' => $this->a->id, 'started_at' => '2026-09-26 11:07:00', 'ended_at' => '2026-09-26 11:07:40', 'duration_s' => 40]);
        Outage::create(['device_id' => $this->b->id, 'started_at' => '2026-09-26 11:52:00']);
        // before the window, ignored
        Outage::create(['device_id' => $this->b->id, 'started_at' => '2026-09-26 09:00:00', 'ended_at' => '2026-09-26 09:30:00', 'duration_s' => 1800]);

        $data = $this->getJson($this->url('from=2026-09-26T11:00:00Z&to=2026-09-26T12:00:00Z&points=12'))
            ->assertOk()->json('data');

        $this->assertSame([1], $data['down'][$this->a->id]);
        $this->assertSame([10, 11], $data['down'][$this->b->id]);
        $this->assertCount(2, $data['outages']);
        $this->assertSame([$this->b->id, Carbon::parse('2026-09-26 11:52:00')->getTimestamp(), null], $data['outages'][1]);
    }

    public function test_long_ranges_stitch_hourly_rollups_with_recent_raw(): void
    {
        $this->actingAsUser();
        // twenty days ago: well past raw retention, so only its hourly rollup is left
        $old = now()->subDays(20)->setTime(6, 0);
        $this->bps($this->ifA->id, $old->copy()->addMinutes(10)->format('Y-m-d H:i:s'), 800, 80);
        $this->bps($this->ifA->id, $old->copy()->addMinutes(40)->format('Y-m-d H:i:s'), 1200, 120);
        app(RollupHistory::class)(0.0);
        $this->assertNotNull(DB::table('interface_rollup_1h')->where('interface_id', $this->ifA->id)->first());
        DB::table('interface_samples')->delete();
        // and some raw from this morning that no rollup has seen yet
        DB::table('history_rollup_state')->update(['rolled_to' => '2026-09-26 00:00:00']);
        $this->bps($this->ifA->id, '2026-09-26 09:00:00', 4000, 40);

        $data = $this->getJson($this->url('from='.now()->subDays(30)->toIso8601ZuluString().'&to='.now()->toIso8601ZuluString()))
            ->assertOk()->json('data');

        $this->assertSame('1h', $data['tier']);
        $this->assertLessThanOrEqual(300, count($data['frames']));
        $this->assertSame(0, $data['step'] % 3600);
        $series = $data['interfaces'][$this->ifA->id]['bps_in'];
        $this->assertCount(count($data['frames']), $series);
        $values = array_values(array_filter($series, fn ($v) => $v !== null));
        // (800 + 1200) / 2 from the hourly row, 4000 from raw
        $this->assertSame([1000, 4000], $values);
    }

    public function test_at_returns_one_frame_for_that_moment(): void
    {
        $this->actingAsUser();
        $this->bps($this->ifA->id, '2026-09-26 10:28:00', 100, 10);
        $this->bps($this->ifA->id, '2026-09-26 10:31:00', 300, 30);
        $this->bps($this->ifA->id, '2026-09-26 10:33:00', 9999, 9999); // after the moment
        $this->bps($this->ifA->id, '2026-09-26 10:20:00', 9999, 9999); // too long before it
        Outage::create(['device_id' => $this->b->id, 'started_at' => '2026-09-26 10:30:00', 'ended_at' => '2026-09-26 10:40:00', 'duration_s' => 600]);
        Outage::create(['device_id' => $this->a->id, 'started_at' => '2026-09-26 10:10:00', 'ended_at' => '2026-09-26 10:29:00', 'duration_s' => 1140]);

        $data = $this->getJson($this->url('at=2026-09-26T10:32:00Z'))->assertOk()->json('data');

        $this->assertSame('at', $data['mode']);
        $this->assertSame([Carbon::parse('2026-09-26 10:32:00')->getTimestamp()], $data['frames']);
        $this->assertSame([200], $data['interfaces'][$this->ifA->id]['bps_in']);
        $this->assertSame([0], $data['down'][$this->b->id]);
        $this->assertArrayNotHasKey((string) $this->a->id, $data['down']); // recovered before 10:32

        // a moment past raw retention is read from the rollup bucket it falls in
        $old = now()->subDays(20)->setTime(6, 0);
        app(ManageHistoryPartitions::class)->ensure('interface_rollup_5m', 'day', $old);
        DB::table('interface_rollup_5m')->insert(['interface_id' => $this->ifA->id, 'bucket' => $old->copy()->addMinutes(5)->format('Y-m-d H:i:s'), 'bps_in_sum' => 90, 'bps_in_cnt' => 3]);
        DB::table('history_rollup_state')->insert(['family' => 'interface', 'tier' => '5m', 'rolled_to' => '2026-09-26 12:00:00']);
        $old = $this->getJson($this->url('at='.$old->copy()->addMinutes(7)->toIso8601ZuluString()))->assertOk()->json('data');
        $this->assertSame('5m', $old['tier']);
        $this->assertSame([30], $old['interfaces'][$this->ifA->id]['bps_in']);
    }

    public function test_only_the_maps_devices_and_links_are_returned(): void
    {
        $this->actingAsUser();
        $off = Device::factory()->create(['name' => 'off-map']);
        $ifOff = NetworkInterface::factory()->create(['device_id' => $off->id]);
        Link::create(['a_device_id' => $this->a->id, 'a_interface_id' => $this->ifA->id, 'b_device_id' => $off->id, 'b_interface_id' => $ifOff->id]);
        $this->bps($ifOff->id, '2026-09-26 11:05:00', 1, 1);
        $this->ping($off->id, '2026-09-26 11:05:00', 5, 0);
        Outage::create(['device_id' => $off->id, 'started_at' => '2026-09-26 11:10:00']);

        $data = $this->getJson($this->url('from=2026-09-26T11:00:00Z&to=2026-09-26T12:00:00Z'))->assertOk()->json('data');

        $this->assertNotContains($off->id, $data['device_ids']);
        $this->assertArrayNotHasKey((string) $ifOff->id, $data['interfaces']);
        $this->assertArrayNotHasKey((string) $off->id, $data['devices']);
        $this->assertSame([], $data['outages']);
    }

    public function test_restricted_operator_gets_404_for_a_map_they_were_not_granted(): void
    {
        $other = Map::factory()->create(['name' => 'Region B']);
        $user = User::factory()->create(['is_admin' => false, 'restricted' => true]);
        $user->maps()->attach($other->id);
        $this->actingAs($user);

        $this->getJson($this->url('from=2026-09-26T11:00:00Z'))->assertNotFound();
        $this->getJson("/api/maps/{$other->id}/playback")->assertOk();

        // and on a granted map they get its devices
        $granted = User::factory()->create(['is_admin' => false, 'restricted' => true]);
        $granted->maps()->attach($this->map->id);
        $this->actingAs($granted)->getJson($this->url('from=2026-09-26T11:00:00Z'))->assertOk()
            ->assertJsonPath('data.device_ids', fn ($ids) => count($ids) === 2);
    }

    public function test_chunks_line_up_with_the_whole_window_across_rollups_and_raw(): void
    {
        $this->actingAsUser();
        // polls every 2 minutes for the last 6 hours, rolled up while it was 10:03 so the 5m tier
        // closes at 10:00 and everything after is raw
        $this->travelTo(Carbon::parse('2026-09-26 10:03:00'));
        for ($t = now()->subHours(4); $t->lessThan(now()); $t->addMinutes(2)) {
            $this->bps($this->ifA->id, $t->format('Y-m-d H:i:s'), $t->minute * 1000, 5);
            $this->ping($this->a->id, $t->format('Y-m-d H:i:s'), $t->minute, 0);
        }
        app(RollupHistory::class)(0.0);
        $this->assertSame('2026-09-26 09:55:00', DB::table('history_rollup_state')->where(['family' => 'interface', 'tier' => '5m'])->value('rolled_to'));
        $this->travelTo(Carbon::parse('2026-09-26 12:00:00'));
        for ($t = Carbon::parse('2026-09-26 10:03:00'); $t->lessThan(now()); $t->addMinutes(2)) {
            $this->bps($this->ifA->id, $t->format('Y-m-d H:i:s'), $t->minute * 1000, 5);
            $this->ping($this->a->id, $t->format('Y-m-d H:i:s'), $t->minute, 0);
        }

        $window = 'from=2026-09-26T06:00:00Z&to=2026-09-26T12:00:00Z&points=72';
        $whole = $this->getJson($this->url("{$window}&limit=300"))->assertOk()->json('data');
        $this->assertSame('5m', $whole['tier']);
        $this->assertSame(72, $whole['limit']);
        $this->assertCount(72, $whole['interfaces'][$this->ifA->id]['bps_in']);

        $stitched = ['bps_in' => [], 'rtt_ms' => []];
        for ($offset = 0; $offset < 72; $offset += 10) {
            $part = $this->getJson($this->url("{$window}&offset={$offset}&limit=10"))->assertOk()->json('data');
            $this->assertSame($offset, $part['offset']);
            $this->assertSame($whole['frames'], $part['frames']);
            $stitched['bps_in'] = [...$stitched['bps_in'], ...$part['interfaces'][$this->ifA->id]['bps_in'] ?? array_fill(0, $part['limit'], null)];
            $stitched['rtt_ms'] = [...$stitched['rtt_ms'], ...$part['devices'][$this->a->id]['rtt_ms'] ?? array_fill(0, $part['limit'], null)];
        }
        $this->assertSame($whole['interfaces'][$this->ifA->id]['bps_in'], $stitched['bps_in']);
        $this->assertEquals($whole['devices'][$this->a->id]['rtt_ms'], $stitched['rtt_ms']);

        // a frame from the rollups and one from raw, both the average of their polls
        $this->assertSame(47000, $whole['interfaces'][$this->ifA->id]['bps_in'][$this->frameOf($whole, '2026-09-26 09:45:00')]); // polls at :45, :47, :49
        $this->assertSame(22000, $whole['interfaces'][$this->ifA->id]['bps_in'][$this->frameOf($whole, '2026-09-26 11:20:00')]); // :21 and :23
    }

    public function test_frames_are_capped_and_bad_windows_refused(): void
    {
        $this->actingAsUser();

        $this->getJson($this->url('points=1000'))->assertUnprocessable();
        $this->getJson($this->url('from=2026-09-26T12:00:00Z&to=2026-09-26T11:00:00Z'))->assertUnprocessable();
        $this->getJson($this->url('at=2026-09-27T12:00:00Z'))->assertUnprocessable(); // the future

        // default: the last 24h, off the 5m tier
        $day = $this->getJson($this->url(''))->assertOk()->json('data');
        $this->assertSame('5m', $day['tier']);
        $this->assertLessThanOrEqual(300, count($day['frames']));
        $this->assertGreaterThanOrEqual(288, count($day['frames']));

        foreach (['1', '6', '24', '168', '720', '8760'] as $hours) {
            foreach ([2, 60, 300] as $points) {
                $from = now()->subHours((int) $hours)->toIso8601ZuluString();
                $data = $this->getJson($this->url("from={$from}&points={$points}"))->assertOk()->json('data');
                $this->assertLessThanOrEqual(300, count($data['frames']), "{$hours}h at {$points}");
                $this->assertGreaterThan(0, count($data['frames']));
            }
        }
    }
}
