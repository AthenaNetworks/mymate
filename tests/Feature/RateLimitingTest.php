<?php

namespace Tests\Feature;

use App\Enums\PollMethod;
use App\Models\Device;
use App\Models\Subnet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RateLimitingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsUser();
        Queue::fake();
    }

    public function test_device_discover_is_rate_limited(): void
    {
        $device = Device::factory()->create();

        for ($i = 0; $i < 10; $i++) {
            $this->postJson("/api/devices/{$device->id}/discover")->assertAccepted();
        }

        $this->postJson("/api/devices/{$device->id}/discover")->assertStatus(429);
    }

    public function test_subnet_scan_is_rate_limited(): void
    {
        $subnet = Subnet::factory()->create();

        for ($i = 0; $i < 10; $i++) {
            $this->postJson("/api/subnets/{$subnet->id}/scan")->assertAccepted();
        }

        $this->postJson("/api/subnets/{$subnet->id}/scan")->assertStatus(429);
    }

    public function test_bulk_upgrade_is_rate_limited(): void
    {
        $device = Device::factory()->create(['poll_method' => PollMethod::RouterOs]);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/devices/upgrade', ['device_ids' => [$device->id]])->assertStatus(202);
        }

        $this->postJson('/api/devices/upgrade', ['device_ids' => [$device->id]])->assertStatus(429);
    }

    public function test_upgrade_preflight_is_rate_limited(): void
    {
        $device = Device::factory()->create();

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/devices/upgrade/preflight', ['device_ids' => [$device->id]])->assertOk();
        }

        $this->postJson('/api/devices/upgrade/preflight', ['device_ids' => [$device->id]])->assertStatus(429);
    }
}
