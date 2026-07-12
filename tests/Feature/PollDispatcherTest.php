<?php

namespace Tests\Feature;

use App\Enums\PollMethod;
use App\Jobs\PollInterfacesBatchJob;
use App\Models\Device;
use App\Services\Polling\PollDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PollDispatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_shards_pollable_devices_into_batch_jobs_covering_each_once(): void
    {
        config(['mymate.poll.shards' => 4]);
        Queue::fake();

        // Both poll methods are pollable now - the driver is chosen per device downstream.
        $ids = Device::factory()->count(7)->create(['poll_method' => PollMethod::Snmp])->pluck('id')
            ->merge(Device::factory()->count(6)->create(['poll_method' => PollMethod::RouterOs])->pluck('id'))
            ->sort()->values()->all();

        $shardsUsed = app(PollDispatcher::class)->dispatch();

        $jobs = Queue::pushed(PollInterfacesBatchJob::class);
        $this->assertCount($shardsUsed, $jobs);

        // Every device (SNMP + RouterOS) is covered exactly once.
        $dispatchedIds = $jobs->flatMap(fn (PollInterfacesBatchJob $j) => $j->deviceIds)->sort()->values()->all();
        $this->assertSame($ids, $dispatchedIds);

        // Each device lands in the shard its hash dictates (deterministic, stable).
        foreach ($jobs as $job) {
            foreach ($job->deviceIds as $id) {
                $this->assertSame(crc32((string) $id) % 4, $job->shard);
            }
            $this->assertSame('poll', $job->queue);
        }
    }

    public function test_scales_a_large_fleet_into_bounded_shards_covering_each_once(): void
    {
        config(['mymate.poll.shards' => 16]);
        Queue::fake();

        $ids = Device::factory()->count(200)->create(['poll_method' => PollMethod::Snmp])->pluck('id')->all();

        $shardsUsed = app(PollDispatcher::class)->dispatch();

        $jobs = Queue::pushed(PollInterfacesBatchJob::class);
        $this->assertCount($shardsUsed, $jobs);
        $this->assertLessThanOrEqual(16, $shardsUsed);

        // Whole fleet covered exactly once; no shard wildly oversized (~ even split).
        $covered = $jobs->flatMap(fn (PollInterfacesBatchJob $j) => $j->deviceIds);
        $this->assertSame(200, $covered->unique()->count());
        $this->assertLessThanOrEqual((int) ceil(200 / 16) * 3, $jobs->max(fn (PollInterfacesBatchJob $j) => count($j->deviceIds)));
    }

    public function test_dispatches_nothing_when_there_are_no_devices(): void
    {
        Queue::fake();

        $this->assertSame(0, app(PollDispatcher::class)->dispatch());
        Queue::assertNotPushed(PollInterfacesBatchJob::class);
    }

    public function test_excludes_devices_with_monitoring_disabled(): void
    {
        config(['mymate.poll.shards' => 4]);
        Queue::fake();

        $monitored = Device::factory()->count(3)->create(['poll_method' => PollMethod::Snmp])->pluck('id')->sort()->values()->all();
        Device::factory()->count(2)->create(['poll_method' => PollMethod::Snmp, 'monitored' => false]); // paused

        app(PollDispatcher::class)->dispatch();

        $dispatchedIds = Queue::pushed(PollInterfacesBatchJob::class)
            ->flatMap(fn (PollInterfacesBatchJob $j) => $j->deviceIds)->sort()->values()->all();

        $this->assertSame($monitored, $dispatchedIds); // unmonitored devices never reach a shard
    }

    public function test_excludes_ping_only_devices_from_throughput_dispatch(): void
    {
        //  (FR-36): a ping-only device has no throughput driver, so it must
        // never reach a poll batch - otherwise the orchestrator would call the driver
        // factory, which throws for `none`, logging a spurious `poll: device poll
        // failed` every tick (the exact noise this feature removes). It is still
        // `monitored` (it gets pinged for up/down) - only throughput is skipped.
        config(['mymate.poll.shards' => 4]);
        Queue::fake();

        $throughput = Device::factory()->count(2)->create(['poll_method' => PollMethod::Snmp])->pluck('id')
            ->merge(Device::factory()->create(['poll_method' => PollMethod::RouterOs])->id)
            ->sort()->values()->all();
        Device::factory()->count(2)->create(['poll_method' => PollMethod::None]); // monitored, but ping-only

        app(PollDispatcher::class)->dispatch();

        $dispatchedIds = Queue::pushed(PollInterfacesBatchJob::class)
            ->flatMap(fn (PollInterfacesBatchJob $j) => $j->deviceIds)->sort()->values()->all();

        $this->assertSame($throughput, $dispatchedIds); // ping-only devices never reach a shard
    }
}
