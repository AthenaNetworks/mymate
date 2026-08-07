<?php

namespace Tests\Feature;

use App\Actions\Polling\PollProbes;
use App\Enums\DeviceStatus;
use App\Models\Device;
use App\Models\Probe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PollProbesTest extends TestCase
{
    use RefreshDatabase;

    private function poll(): void
    {
        app(PollProbes::class)();
    }

    /** Force the probe to be "due" again for the next tick (it was just checked). */
    private function makeDue(Probe $probe): void
    {
        $probe->forceFill(['checked_at' => now()->subHour()])->save();
    }

    public function test_flap_dampening_holds_until_the_threshold_then_recovers(): void
    {
        $device = Device::factory()->create();
        $probe = Probe::factory()->create([
            'device_id' => $device->id,
            'fail_threshold' => 3,
            'config' => ['url' => 'https://x.test/', 'expect_status' => '200-399'],
        ]);

        // Three misses then a recovery, in order (a sequence, so each poll pops the next).
        Http::fakeSequence()
            ->push('down', 500)
            ->push('down', 500)
            ->push('down', 500)
            ->push('ok', 200);

        $this->poll();                       // miss 1
        $this->assertNotSame(DeviceStatus::Down, $probe->refresh()->status);
        $this->assertSame(1, $probe->fail_streak);

        $this->makeDue($probe);
        $this->poll();                       // miss 2
        $this->assertNotSame(DeviceStatus::Down, $probe->refresh()->status);

        $this->makeDue($probe);
        $this->poll();                       // miss 3 -> down
        $this->assertSame(DeviceStatus::Down, $probe->refresh()->status);
        $this->assertSame('HTTP 500', $probe->message);

        // One good check recovers immediately.
        $this->makeDue($probe);
        $this->poll();
        $this->assertSame(DeviceStatus::Up, $probe->refresh()->status);
        $this->assertSame(0, $probe->fail_streak);
    }

    public function test_records_a_trend_sample_and_stamps_checked_at(): void
    {
        $probe = Probe::factory()->create(['config' => ['url' => 'https://x.test/']]);
        Http::fake(['*' => Http::response('ok', 200)]);

        $this->poll();

        $this->assertNotNull($probe->refresh()->checked_at);
        $this->assertSame(1, DB::table('probe_samples')->where('probe_id', $probe->id)->count());
        $this->assertTrue((bool) DB::table('probe_samples')->where('probe_id', $probe->id)->value('up'));
    }

    public function test_skips_probes_that_are_not_yet_due(): void
    {
        $probe = Probe::factory()->create(['interval_s' => 300, 'config' => ['url' => 'https://x.test/']]);
        Http::fake(['*' => Http::response('ok', 200)]);

        $this->poll();                       // first run - due (never checked)
        $first = $probe->refresh()->checked_at;

        $this->poll();                       // immediately again - not due, so no new sample
        $this->assertEquals($first, $probe->refresh()->checked_at);
        $this->assertSame(1, DB::table('probe_samples')->where('probe_id', $probe->id)->count());
    }

    public function test_disabled_probes_are_not_run(): void
    {
        $probe = Probe::factory()->create(['enabled' => false, 'config' => ['url' => 'https://x.test/']]);
        Http::fake(['*' => Http::response('ok', 200)]);

        $this->poll();

        $this->assertNull($probe->refresh()->checked_at);
    }
}
