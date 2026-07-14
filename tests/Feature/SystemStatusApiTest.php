<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemStatusApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/system-status')->assertUnauthorized();
    }

    public function test_it_returns_a_health_board(): void
    {
        $this->actingAsUser();

        $res = $this->getJson('/api/system-status')->assertOk();

        $keys = collect($res->json('data'))->pluck('key')->all();
        // The full set of probes the panel renders.
        foreach (['database', 'redis', 'workers', 'polling', 'websockets', 'backups'] as $expected) {
            $this->assertContains($expected, $keys, "missing status check: {$expected}");
        }

        // Every row is well-formed and carries a known status level.
        foreach ($res->json('data') as $row) {
            $this->assertArrayHasKey('label', $row);
            $this->assertArrayHasKey('detail', $row);
            $this->assertContains($row['status'], ['ok', 'warn', 'down']);
        }
    }

    public function test_polling_reports_ok_from_sample_activity_when_the_heartbeat_is_absent(): void
    {
        // No heartbeat (loop process predates the heartbeat code) but samples are being written -
        // polling is clearly alive and must not false-alarm.
        \Illuminate\Support\Facades\Cache::forget(\App\Console\Commands\LoopCommand::HEARTBEAT_KEY);
        \Illuminate\Support\Facades\DB::table('ping_samples')->insert([
            'device_id' => 1, 'ts' => now()->format('Y-m-d H:i:s'), 'rtt_ms' => 5, 'loss_pct' => 0, 'jitter_ms' => 1,
        ]);

        $polling = collect(app(\App\Support\SystemStatus::class)->check())->firstWhere('key', 'polling');

        $this->assertSame('ok', $polling['status']);
        $this->assertStringContainsString('Polling active', $polling['detail']);
    }

    public function test_polling_reports_down_with_no_heartbeat_and_no_activity(): void
    {
        \Illuminate\Support\Facades\Cache::forget(\App\Console\Commands\LoopCommand::HEARTBEAT_KEY);

        $polling = collect(app(\App\Support\SystemStatus::class)->check())->firstWhere('key', 'polling');

        // Nothing polled and no heartbeat -> a warn (still starting / nothing to poll), never a
        // silent "ok".
        $this->assertContains($polling['status'], ['warn', 'down']);
    }
}
