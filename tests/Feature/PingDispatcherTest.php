<?php

namespace Tests\Feature;

use App\Jobs\PingSweepJob;
use App\Models\Device;
use App\Models\DeviceMapPosition;
use App\Models\Map;
use App\Services\Polling\PingDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PingDispatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_shard_dispatches_one_whole_fleet_sweep(): void
    {
        config(['mymate.ping.shards' => 1]);
        Queue::fake();
        Device::factory()->count(5)->create(['monitored' => true]);

        $count = app(PingDispatcher::class)->dispatch();

        $this->assertSame(1, $count);
        $jobs = Queue::pushed(PingSweepJob::class);
        $this->assertCount(1, $jobs);
        $this->assertSame('ping', $jobs->first()->queue);
    }

    public function test_no_monitored_devices_dispatches_nothing(): void
    {
        config(['mymate.ping.shards' => 1]);
        Queue::fake();
        Device::factory()->count(3)->create(['monitored' => false]);

        $this->assertSame(0, app(PingDispatcher::class)->dispatch());
        Queue::assertNothingPushed();
    }

    public function test_shards_the_fleet_into_parallel_sweeps_on_the_ping_queue(): void
    {
        config(['mymate.ping.shards' => 4]);
        Queue::fake();
        // Agent-assigned devices are pinged by their agent, never dispatched from here.
        Device::factory()->count(20)->create(['monitored' => true, 'agent_id' => null]);

        $shardsUsed = app(PingDispatcher::class)->dispatch();

        $jobs = Queue::pushed(PingSweepJob::class);
        $this->assertCount($shardsUsed, $jobs);
        $this->assertLessThanOrEqual(4, $shardsUsed);
        foreach ($jobs as $job) {
            $this->assertSame('ping', $job->queue);
        }
    }

    public function test_base_interval_follows_the_fastest_map_override(): void
    {
        $d = app(PingDispatcher::class);
        $this->assertSame(60, $d->baseInterval(60)); // no map override -> the global interval

        Map::factory()->create(['ping_interval' => 10]);
        Map::factory()->create(['ping_interval' => 3]);
        Map::factory()->create(['ping_interval' => null]); // no opinion, ignored

        $this->assertSame(3, $d->baseInterval(60)); // fastest override wins
        $this->assertSame(2, $d->baseInterval(2));  // a faster global still wins
    }

    public function test_a_faster_map_pings_its_devices_on_its_own_cadence(): void
    {
        config(['mymate.ping.shards' => 1]);
        Cache::flush();
        Queue::fake();

        // Global is the default 5s; the "fast" map overrides its devices to 2s.
        $fastMap = Map::factory()->create(['ping_interval' => 2]);
        $onFast = Device::factory()->create(['monitored' => true]);
        DeviceMapPosition::create(['device_id' => $onFast->id, 'map_id' => $fastMap->id, 'x' => 0, 'y' => 0]);
        $global = Device::factory()->create(['monitored' => true]); // on no override map -> 5s bucket

        // First tick: both buckets are fresh, so both fire.
        app(PingDispatcher::class)->dispatch();
        $first = $this->sweptIds(Queue::pushed(PingSweepJob::class)->last());
        $this->assertContains($onFast->id, $first);
        $this->assertContains($global->id, $first);

        // Advance only the 2s bucket's due time (the 5s bucket just ran and isn't due yet).
        $now = microtime(true);
        Cache::put('ping:cadence:last:5', $now, now()->addDay());
        Cache::put('ping:cadence:last:2', $now - 3, now()->addDay());

        app(PingDispatcher::class)->dispatch();
        $second = $this->sweptIds(Queue::pushed(PingSweepJob::class)->last());
        $this->assertContains($onFast->id, $second);    // fast-map device swept again
        $this->assertNotContains($global->id, $second); // global-cadence device not due yet
    }

    /** @return list<int> the device ids a PingSweepJob was dispatched for (private on the job). */
    private function sweptIds(PingSweepJob $job): array
    {
        $prop = new \ReflectionProperty($job, 'deviceIds');
        $prop->setAccessible(true);

        return $prop->getValue($job) ?? [];
    }
}
