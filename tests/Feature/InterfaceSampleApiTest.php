<?php

namespace Tests\Feature;

use App\Models\NetworkInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InterfaceSampleApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsUser();
    }

    private function insertSample(int $ifaceId, Carbon $ts, ?float $utilIn, ?float $utilOut, ?float $bpsIn = null, ?float $bpsOut = null): void
    {
        DB::table('interface_samples')->insert([
            'interface_id' => $ifaceId,
            'ts' => $ts->format('Y-m-d H:i:s'),
            'util_in' => $utilIn,
            'util_out' => $utilOut,
            'bps_in' => $bpsIn,
            'bps_out' => $bpsOut,
        ]);
    }

    public function test_returns_a_bucketed_averaged_series(): void
    {
        $iface = NetworkInterface::factory()->create();
        // Two samples ~1s apart, ~5 min ago - they share a bucket (~60s) and average.
        $base = now()->subMinutes(5);
        $this->insertSample($iface->id, $base, 10, 5);
        $this->insertSample($iface->id, $base->copy()->addSecond(), 20, 15);
        config(['mymate.history.max_points' => 10]); // 600s window / 10 ~ 60s buckets

        $data = $this->getJson(
            "/api/interfaces/{$iface->id}/samples?from=".now()->subMinutes(10)->format('Y-m-d\TH:i:s').'&to='.now()->format('Y-m-d\TH:i:s')
        )->assertOk()->json('data');

        $this->assertNotEmpty($data);
        $bucket = collect($data)->first(fn ($r) => $r['util_in'] !== null);
        $this->assertEqualsWithDelta(15.0, $bucket['util_in'], 0.001);  // (10+20)/2
        $this->assertEqualsWithDelta(10.0, $bucket['util_out'], 0.001); // (5+15)/2
    }

    public function test_defaults_to_the_recent_window_when_no_range_given(): void
    {
        $iface = NetworkInterface::factory()->create();
        $this->insertSample($iface->id, now()->subMinutes(2), 42, 1);

        $data = $this->getJson("/api/interfaces/{$iface->id}/samples")->assertOk()->json('data');

        $this->assertNotEmpty($data);
    }

    public function test_caps_the_point_count(): void
    {
        $iface = NetworkInterface::factory()->create();
        for ($i = 1; $i <= 30; $i++) {
            $this->insertSample($iface->id, now()->subMinutes($i), $i, $i);
        }
        config(['mymate.history.max_points' => 12]);

        $data = $this->getJson(
            "/api/interfaces/{$iface->id}/samples?from=".now()->subMinutes(40)->format('Y-m-d\TH:i:s').'&to='.now()->format('Y-m-d\TH:i:s')
        )->assertOk()->json('data');

        // Downsampled to roughly max_points buckets (never an unbounded raw dump).
        $this->assertLessThanOrEqual(13, count($data));
    }

    public function test_unknown_interface_is_404(): void
    {
        $this->getJson('/api/interfaces/999999/samples')->assertNotFound();
    }

    public function test_validates_bad_dates(): void
    {
        $iface = NetworkInterface::factory()->create();
        $this->getJson("/api/interfaces/{$iface->id}/samples?from=not-a-date")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['from']);
    }
}
