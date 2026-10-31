<?php

namespace Tests\Feature;

use App\Jobs\BulkUpgradeJob;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Tests\TestCase;

class BulkUpgradeJobTest extends TestCase
{
    public function test_timeout_grows_with_the_selection(): void
    {
        config(['mymate.upgrade.reboot_wait_s' => 300]);

        $this->assertSame(900, (new BulkUpgradeJob([1]))->timeout);                 // floor
        $this->assertSame(10 * 480, (new BulkUpgradeJob(range(1, 10)))->timeout);   // 10 x (300 + 180)
        $this->assertSame(21600, (new BulkUpgradeJob(range(1, 500)))->timeout);     // capped
    }

    public function test_the_overlap_lock_expires_after_the_job_could_have_run(): void
    {
        $job = new BulkUpgradeJob(range(1, 10));

        $lock = collect($job->middleware())->first(fn ($m) => $m instanceof WithoutOverlapping);

        $this->assertNotNull($lock);
        $this->assertSame($job->timeout + 60, $lock->expiresAfter);
    }

    public function test_long_queues_outlast_their_jobs(): void
    {
        $retryAfter = config('queue.connections.redis-long.retry_after');
        foreach (['supervisor-upgrade', 'supervisor-backup', 'supervisor-trace', 'supervisor-import'] as $name) {
            $supervisor = config("horizon.defaults.{$name}");
            $this->assertSame('redis-long', $supervisor['connection'], $name);
            $this->assertGreaterThan($supervisor['timeout'], $retryAfter, $name);
        }
        $this->assertGreaterThan((new BulkUpgradeJob(range(1, 500)))->timeout, $retryAfter);
    }
}
