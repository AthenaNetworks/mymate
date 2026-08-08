<?php

namespace Tests\Feature;

use App\Jobs\RunTraceJob;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TraceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_start_seeds_the_snapshot_dispatches_the_job_and_records_the_owner(): void
    {
        Queue::fake();
        $user = $this->actingAsUser();
        $device = Device::factory()->create(['mgmt_ip' => '10.0.0.1']);

        $runId = $this->postJson("/api/devices/{$device->id}/trace")->assertStatus(202)->json('run_id');

        Queue::assertPushed(RunTraceJob::class);
        $this->assertNotNull(Cache::get("trace:{$runId}"));
        $this->assertSame($user->id, Cache::get("trace:{$runId}:owner"));
    }

    public function test_start_422s_without_a_management_ip(): void
    {
        Queue::fake();
        $this->actingAsUser();
        $device = Device::factory()->create(['mgmt_ip' => '']); // column is NOT NULL; empty = "no IP to trace"

        $this->postJson("/api/devices/{$device->id}/trace")->assertStatus(422);
        Queue::assertNothingPushed();
    }

    public function test_only_the_starter_or_an_admin_can_stop_a_run(): void
    {
        Queue::fake();
        $starter = User::factory()->create(['is_admin' => false]);
        $other = User::factory()->create(['is_admin' => false]);
        $device = Device::factory()->create(['mgmt_ip' => '10.0.0.1']);

        $runId = $this->actingAs($starter)->postJson("/api/devices/{$device->id}/trace")->json('run_id');

        // A different (non-admin) operator on the same device cannot cancel it.
        $this->actingAs($other)->deleteJson("/api/devices/{$device->id}/trace/{$runId}")->assertForbidden();
        $this->assertNull(Cache::get("trace:{$runId}:stop"));

        // The operator who started it can.
        $this->actingAs($starter)->deleteJson("/api/devices/{$device->id}/trace/{$runId}")->assertNoContent();
        $this->assertTrue((bool) Cache::get("trace:{$runId}:stop"));
    }

    public function test_show_is_scoped_to_the_device_that_owns_the_run(): void
    {
        Queue::fake();
        $this->actingAsUser();
        $a = Device::factory()->create(['mgmt_ip' => '10.0.0.1']);
        $b = Device::factory()->create(['mgmt_ip' => '10.0.0.2']);

        $runId = $this->postJson("/api/devices/{$a->id}/trace")->json('run_id');

        $this->getJson("/api/devices/{$b->id}/trace/{$runId}")->assertNotFound();
        $this->getJson("/api/devices/{$a->id}/trace/{$runId}")->assertOk()->assertJsonPath('status', 'running');
    }
}
