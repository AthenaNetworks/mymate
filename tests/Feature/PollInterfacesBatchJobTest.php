<?php

namespace Tests\Feature;

use App\Actions\Polling\PollInterfaces;
use App\Jobs\PollInterfacesBatchJob;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use PHPUnit\Framework\TestCase;

class PollInterfacesBatchJobTest extends TestCase
{
    public function test_handle_delegates_its_shard_device_ids_to_the_orchestrator(): void
    {
        $spy = new class extends PollInterfaces
        {
            public array $received = [];

            public function __construct() {}

            public function __invoke(array $deviceIds): int
            {
                $this->received = $deviceIds;

                return count($deviceIds);
            }
        };

        (new PollInterfacesBatchJob(2, [5, 6, 7]))->handle($spy);

        $this->assertSame([5, 6, 7], $spy->received);
    }

    public function test_is_guarded_by_a_per_shard_overlap_lock(): void
    {
        $middleware = (new PollInterfacesBatchJob(3, [1, 2]))->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
    }
}
