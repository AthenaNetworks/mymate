<?php

namespace Tests\Feature;

use App\Support\BackupSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BackupScheduleTest extends TestCase
{
    use RefreshDatabase;

    private function schedule(): BackupSchedule
    {
        return app(BackupSchedule::class);
    }

    public function test_disabled_is_never_due(): void
    {
        $s = $this->schedule();
        $s->save(['enabled' => false, 'frequency' => 'hourly', 'hour' => 2, 'weekday' => 0]);
        $this->assertFalse($s->due(Carbon::parse('2026-07-13 02:00:00')));
    }

    public function test_daily_fires_at_the_configured_hour_once_per_day(): void
    {
        $s = $this->schedule();
        $s->save(['enabled' => true, 'frequency' => 'daily', 'hour' => 2, 'weekday' => 0]);

        $this->assertFalse($s->due(Carbon::parse('2026-07-13 01:00:00'))); // wrong hour
        $this->assertTrue($s->due(Carbon::parse('2026-07-13 02:30:00')));   // right hour, not run yet

        $s->markRan(Carbon::parse('2026-07-13 02:30:00'));
        $this->assertFalse($s->due(Carbon::parse('2026-07-13 02:45:00'))); // already ran today
        $this->assertTrue($s->due(Carbon::parse('2026-07-14 02:05:00')));  // next day
    }

    public function test_interval_frequency_respects_the_gap(): void
    {
        $s = $this->schedule();
        $s->save(['enabled' => true, 'frequency' => 'every_6h', 'hour' => 2, 'weekday' => 0]);

        $this->assertTrue($s->due(Carbon::parse('2026-07-13 00:00:00'))); // never run -> due
        $s->markRan(Carbon::parse('2026-07-13 00:00:00'));
        $this->assertFalse($s->due(Carbon::parse('2026-07-13 03:00:00'))); // only 3h later
        $this->assertTrue($s->due(Carbon::parse('2026-07-13 06:00:00')));  // 6h later
    }

    public function test_endpoint_reads_and_writes_the_schedule(): void
    {
        $this->actingAsUser();

        $this->getJson('/api/settings/backup-schedule')
            ->assertOk()
            ->assertJsonPath('data.frequency', 'daily'); // default

        $this->putJson('/api/settings/backup-schedule', ['enabled' => true, 'frequency' => 'weekly', 'hour' => 3, 'weekday' => 1])
            ->assertOk()
            ->assertJsonPath('data.frequency', 'weekly')
            ->assertJsonPath('data.hour', 3);
    }
}
