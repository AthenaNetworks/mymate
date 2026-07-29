<?php

namespace Tests\Feature;

use App\Jobs\PingSweepJob;
use App\Models\Device;
use App\Services\Polling\PingDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
