<?php

namespace Tests\Feature;

use App\Actions\Agent\DispatchAgentJobs;
use App\Actions\Devices\CaptureDeviceFacts;
use App\Actions\Polling\DiscoverInterfaces;
use App\Actions\Polling\PollInterfaces;
use App\Enums\PollMethod;
use App\Jobs\DiscoverInterfacesJob;
use App\Jobs\PollInterfacesJob;
use App\Models\Agent;
use App\Models\Credential;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DiscoverDeviceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_queues_discovery_for_a_device(): void
    {
        Queue::fake();
        $this->actingAsUser();
        $device = Device::factory()->create();

        $this->postJson("/api/devices/{$device->id}/discover")
            ->assertAccepted()
            ->assertJsonPath('message', "Interface discovery queued for {$device->name}.");

        Queue::assertPushed(DiscoverInterfacesJob::class, fn ($job): bool => $job->deviceId === $device->id);
    }

    public function test_requires_authentication(): void
    {
        Queue::fake();
        $device = Device::factory()->create();

        $this->postJson("/api/devices/{$device->id}/discover")->assertUnauthorized();

        Queue::assertNotPushed(DiscoverInterfacesJob::class);
    }

    // GitHub #54: "Discover now" (and approving a candidate) on a device behind an agent walked it
    // from this server instead of asking the agent.
    public function test_discovery_for_an_agent_device_is_handed_to_its_agent(): void
    {
        $agent = Agent::factory()->create();
        $device = Device::factory()->create([
            'agent_id' => $agent->id,
            'monitored' => true,
            'poll_method' => PollMethod::Snmp,
            'credential_id' => Credential::factory()->create()->id,
        ]);
        // The agent discovered a moment ago, so it wouldn't be due again for the whole interval.
        Cache::put("agent:{$agent->id}:last_discover", now()->timestamp, now()->addDay());

        $discover = $this->mock(DiscoverInterfaces::class);
        $discover->shouldNotReceive('__invoke');
        $facts = $this->mock(CaptureDeviceFacts::class);
        $facts->shouldNotReceive('__invoke');

        (new DiscoverInterfacesJob($device->id))->handle($discover, $facts);

        $job = app(DispatchAgentJobs::class)->buildJob($agent->id);
        $this->assertCount(1, $job['poll']['snmp']);
        $this->assertTrue($job['poll']['snmp'][0]['discover']);
    }

    public function test_single_device_poll_skips_agent_devices(): void
    {
        $agent = Agent::factory()->create();
        $device = Device::factory()->create(['agent_id' => $agent->id]);

        $poll = $this->mock(PollInterfaces::class);
        $poll->shouldNotReceive('__invoke');

        (new PollInterfacesJob($device->id))->handle($poll);
    }

    public function test_single_device_poll_still_runs_for_central_devices(): void
    {
        $device = Device::factory()->create(['agent_id' => null]);

        $poll = $this->mock(PollInterfaces::class);
        $poll->shouldReceive('__invoke')->once()->with([$device->id]);

        (new PollInterfacesJob($device->id))->handle($poll);
    }
}
