<?php

namespace Tests\Feature;

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeviceMetricSampleApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsUser();
    }

    private function insert(int $deviceId, Carbon $ts, ?float $cpu, ?float $mem, ?float $temp): void
    {
        DB::table('device_metric_samples')->insert([
            'device_id' => $deviceId,
            'ts' => $ts->format('Y-m-d H:i:s'),
            'cpu_pct' => $cpu,
            'mem_used_pct' => $mem,
            'temp_c' => $temp,
        ]);
    }

    public function test_returns_a_bucketed_averaged_metric_series(): void
    {
        $device = Device::factory()->create();
        $base = now()->subMinutes(5);
        $this->insert($device->id, $base, 10, 40, 50);
        $this->insert($device->id, $base->copy()->addSecond(), 30, 60, 52);
        config(['mymate.history.max_points' => 10]);

        $data = $this->getJson(
            "/api/devices/{$device->id}/metric-samples?from=".now()->subMinutes(10)->format('Y-m-d\TH:i:s').'&to='.now()->format('Y-m-d\TH:i:s')
        )->assertOk()->json('data');

        $bucket = collect($data)->first(fn ($r) => $r['cpu_pct'] !== null);
        $this->assertEqualsWithDelta(20.0, $bucket['cpu_pct'], 0.001);   // (10+30)/2
        $this->assertEqualsWithDelta(50.0, $bucket['mem_used_pct'], 0.001); // (40+60)/2
        $this->assertEqualsWithDelta(51.0, $bucket['temp_c'], 0.001);    // (50+52)/2
    }

    public function test_unknown_device_is_404(): void
    {
        $this->getJson('/api/devices/999999/metric-samples')->assertNotFound();
    }

    public function test_validates_bad_dates(): void
    {
        $device = Device::factory()->create();
        $this->getJson("/api/devices/{$device->id}/metric-samples?from=not-a-date")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['from']);
    }

    public function test_exposes_latest_metrics_on_the_device_resource(): void
    {
        Device::factory()->create(['cpu_pct' => 12.5, 'mem_used_pct' => 44.25, 'temp_c' => 39.5]);

        $this->getJson('/api/devices')
            ->assertOk()
            ->assertJsonPath('data.0.cpu_pct', 12.5)
            ->assertJsonPath('data.0.mem_used_pct', 44.25)
            ->assertJsonPath('data.0.temp_c', 39.5);
    }
}
