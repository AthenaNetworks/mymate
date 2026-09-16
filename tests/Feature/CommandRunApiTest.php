<?php

namespace Tests\Feature;

use App\Jobs\Tools\RunCommandJob;
use App\Models\Device;
use App\Models\User;
use App\Models\CommandTemplate;
use App\Models\CommandRun;
use App\Models\CommandRunTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CommandRunApiTest extends TestCase
{
    use RefreshDatabase;

    private function configureEngine(): void
    {
        config()->set('mymate.backup.url', 'http://127.0.0.1:8410');
        config()->set('mymate.backup.token', 'test-token');
    }

    public function test_admin_can_start_a_command_for_selected_devices(): void
    {
        $this->configureEngine();
        Queue::fake();
        $admin = $this->actingAsUser();
        $a = Device::factory()->create(['name' => 'router-a']);
        $b = Device::factory()->create(['name' => 'router-b']);

        $response = $this->postJson('/api/command-runs', [
            'device_ids' => [$a->id, $b->id],
            'command' => ' /system resource print ',
            'timeout' => 15,
            'confirm' => true,
        ])->assertStatus(202)->assertJson(['kind' => 'command', 'status' => 'running']);

        Queue::assertPushed(RunCommandJob::class, 2);
        Queue::assertPushed(RunCommandJob::class, fn ($job) => $job->command === '/system resource print' && $job->timeoutSeconds === 15 && $job->queue === 'command');
        $this->getJson('/api/command-runs/'.$response->json('run_id'))
            ->assertOk()
            ->assertJsonPath('result.devices.'.$a->id.'.status', 'queued')
            ->assertJsonPath('result.devices.'.$b->id.'.status', 'queued');

        $this->assertDatabaseHas('command_runs', [
            'id' => $response->json('run_id'),
            'user_id' => $admin->id,
            'command' => '/system resource print',
            'timeout_seconds' => 15,
            'command_hash' => hash('sha256', '/system resource print'),
        ]);
        $this->assertDatabaseCount('command_run_targets', 2);
        $this->assertDatabaseHas('command_run_targets', ['run_id' => $response->json('run_id'), 'device_id' => $a->id, 'status' => 'queued']);
        $this->assertSame($response->json('run_id'), CommandRun::findOrFail($response->json('run_id'))->id);
        $this->assertCount(2, CommandRun::findOrFail($response->json('run_id'))->targets);
    }

    public function test_non_admin_cannot_start_a_command_run(): void
    {
        $this->configureEngine();
        Queue::fake();
        $device = Device::factory()->create();
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        $this->postJson('/api/command-runs', ['device_ids' => [$device->id], 'command' => '/system identity print'])
            ->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_command_run_requires_explicit_confirmation(): void
    {
        $this->configureEngine();
        Queue::fake();
        $this->actingAsUser();
        $device = Device::factory()->create();

        $this->postJson('/api/command-runs', ['device_ids' => [$device->id], 'command' => '/system identity print'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('confirm');
        Queue::assertNothingPushed();
    }

    public function test_only_the_starter_or_admin_can_view_a_command_run(): void
    {
        $this->configureEngine();
        Queue::fake();
        $starter = User::factory()->create(['is_admin' => true]);
        $other = User::factory()->create(['is_admin' => false]);
        $device = Device::factory()->create();

        $runId = $this->actingAs($starter)->postJson('/api/command-runs', [
            'device_ids' => [$device->id], 'command' => '/system identity print', 'confirm' => true,
        ])->json('run_id');

        $this->actingAs($other)->getJson('/api/command-runs/'.$runId)->assertForbidden();
        $this->actingAs($starter)->getJson('/api/command-runs/'.$runId)->assertOk();
    }

    public function test_command_run_rejects_empty_command_and_missing_devices(): void
    {
        $this->configureEngine();
        Queue::fake();
        $this->actingAsUser();

        $this->postJson('/api/command-runs', ['device_ids' => [], 'command' => ''])->assertStatus(422);
        Queue::assertNothingPushed();
    }

    public function test_admin_can_create_and_list_command_templates(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/command-templates', [
            'name' => 'Router identity',
            'command' => '/system identity print',
            'timeout' => 15,
        ])->assertCreated()
            ->assertJsonPath('template.name', 'Router identity')
            ->assertJsonPath('template.command', '/system identity print')
            ->assertJsonPath('template.enabled', true);

        $this->getJson('/api/command-templates')->assertOk()
            ->assertJsonCount(1, 'templates');
    }

    public function test_non_admin_cannot_manage_command_templates(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        $this->postJson('/api/command-templates', [
            'name' => 'Blocked', 'command' => '/system identity print', 'timeout' => 15,
        ])->assertForbidden();

        $this->assertDatabaseCount('command_templates', 0);
    }

    public function test_non_admin_cannot_read_command_templates(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        $this->getJson('/api/command-templates')->assertForbidden();
    }

    public function test_template_run_requires_the_exact_version_and_hash(): void
    {
        $this->configureEngine();
        Queue::fake();
        $admin = $this->actingAsUser();
        $template = CommandTemplate::create([
            'name' => 'Identity', 'command' => '/system identity print', 'timeout_seconds' => 15,
            'enabled' => true, 'created_by' => $admin->id, 'updated_by' => $admin->id,
        ]);
        $device = Device::factory()->create();
        $base = ['template_id' => $template->id, 'device_ids' => [$device->id], 'confirm' => true];

        $this->postJson('/api/command-runs', $base)->assertStatus(422)->assertJsonValidationErrors('template_id');
        $this->postJson('/api/command-runs', [...$base, 'template_updated_at' => $template->updated_at->toISOString(), 'command_hash' => 'wrong'])
            ->assertStatus(422)->assertJsonValidationErrors('command_hash');
        $this->postJson('/api/command-runs', [...$base, 'template_updated_at' => $template->updated_at->toISOString(), 'command_hash' => $template->commandHash()])
            ->assertStatus(202);
        Queue::assertPushed(RunCommandJob::class, 1);
    }

    public function test_template_run_rejects_malformed_and_stale_versions_but_accepts_equivalent_offsets(): void
    {
        $this->configureEngine();
        Queue::fake();
        $admin = $this->actingAsUser();
        $template = CommandTemplate::create([
            'name' => 'Versioned identity', 'command' => '/system identity print', 'timeout_seconds' => 15,
            'enabled' => true, 'created_by' => $admin->id, 'updated_by' => $admin->id,
        ]);
        $device = Device::factory()->create();
        $hash = $template->commandHash();
        $base = ['template_id' => $template->id, 'device_ids' => [$device->id], 'confirm' => true, 'command_hash' => $hash];

        $this->postJson('/api/command-runs', [...$base, 'template_updated_at' => 'not-a-timestamp'])
            ->assertStatus(422)->assertJsonValidationErrors('template_updated_at');
        $equivalent = $template->updated_at->toIso8601String();
        $this->postJson('/api/command-runs', [...$base, 'template_updated_at' => $equivalent])->assertStatus(202);

        $template->updated_at = $template->updated_at->copy()->addMinute();
        $template->command = '/system resource print';
        $template->saveQuietly();
        $this->postJson('/api/command-runs', [...$base, 'template_updated_at' => $equivalent])
            ->assertStatus(422)->assertJsonValidationErrors('template_id');
    }

    public function test_template_names_are_trimmed_before_unique_validation(): void
    {
        $this->actingAsUser();
        $payload = ['name' => '  Identity  ', 'command' => '/system identity print', 'timeout' => 15];
        $this->postJson('/api/command-templates', $payload)->assertCreated();
        $this->postJson('/api/command-templates', [...$payload, 'name' => ' Identity '])
            ->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_disabled_template_cannot_start_a_run(): void
    {
        $this->configureEngine();
        Queue::fake();
        $admin = $this->actingAsUser();
        $template = CommandTemplate::create([
            'name' => 'Disabled', 'command' => '/system identity print', 'timeout_seconds' => 15,
            'enabled' => false, 'created_by' => $admin->id, 'updated_by' => $admin->id,
        ]);
        $device = Device::factory()->create();

        $this->postJson('/api/command-runs', [
            'template_id' => $template->id, 'device_ids' => [$device->id], 'confirm' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('template_id');
        Queue::assertNothingPushed();
    }
}
