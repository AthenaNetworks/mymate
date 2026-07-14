<?php

namespace Tests\Feature;

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PingSampleApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsUser();
    }

    private function insert(int $deviceId, Carbon $ts, ?float $rtt, ?float $loss, ?float $jitter): void
    {
        DB::table('ping_samples')->insert([
            'device_id' => $deviceId,
            'ts' => $ts->format('Y-m-d H:i:s'),
            'rtt_ms' => $rtt,
            'loss_pct' => $loss,
            'jitter_ms' => $jitter,
        ]);
    }

    public function test_returns_a_bucketed_averaged_latency_series(): void
    {
        $device = Device::factory()->create();
        $base = now()->subMinutes(5);
        // Two samples in the same bucket: rtt (2,4)->3, loss (0,100)->50%, jitter (1,3)->2.
        $this->insert($device->id, $base, 2, 0, 1);
        $this->insert($device->id, $base->copy()->addSecond(), 4, 100, 3);
        config(['mymate.history.max_points' => 10]);

        $data = $this->getJson(
            "/api/devices/{$device->id}/ping-samples?from=".now()->subMinutes(10)->format('Y-m-d\TH:i:s').'&to='.now()->format('Y-m-d\TH:i:s')
        )->assertOk()->json('data');

        $bucket = collect($data)->first(fn ($r) => $r['rtt_ms'] !== null);
        $this->assertEqualsWithDelta(3.0, $bucket['rtt_ms'], 0.001);
        $this->assertEqualsWithDelta(50.0, $bucket['loss_pct'], 0.001); // binary loss averages to a real %
        $this->assertEqualsWithDelta(2.0, $bucket['jitter_ms'], 0.001);
    }
}
