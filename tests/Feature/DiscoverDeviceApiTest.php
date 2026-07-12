<?php

namespace Tests\Feature;

use App\Jobs\DiscoverInterfacesJob;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
