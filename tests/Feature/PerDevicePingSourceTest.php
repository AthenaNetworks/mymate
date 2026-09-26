<?php

namespace Tests\Feature;

use App\Actions\Agent\DispatchAgentJobs;
use App\Actions\Polling\PingFleet;
use App\Enums\DeviceStatus;
use App\Models\Agent;
use App\Models\Device;
use App\Services\Ping\FpingRunner;
use App\Services\Ping\Pinger;
use App\Services\Ping\PingSample;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Per-device ping source (GitHub #11). The global MYMATE_PING_SOURCE stays the default; a
 * device can override it, the central sweep runs one fping per distinct source, and an agent
 * gets the source in its ping target so it can bind to it.
 */
class PerDevicePingSourceTest extends TestCase
{
    use RefreshDatabase;

    /** @var \ArrayObject<int, array{ips: list<string>, source: ?string}> */
    private \ArrayObject $calls;

    private function recordingPinger(): void
    {
        $this->calls = new \ArrayObject;
        $calls = $this->calls;
        $this->app->bind(Pinger::class, fn () => new class($calls) implements Pinger
        {
            public function __construct(private \ArrayObject $calls) {}

            public function reachable(array $ips): array
            {
                return $ips;
            }

            public function measure(array $ips, ?string $source = null): array
            {
                sort($ips);
                $this->calls[] = ['ips' => $ips, 'source' => $source];
                $out = [];
                foreach ($ips as $ip) {
                    $out[$ip] = new PingSample(reachable: true, rttMs: 1.0, lossPct: 0.0, jitterMs: null);
                }

                return $out;
            }
        });
    }

    public function test_the_sweep_runs_one_ping_per_source_group(): void
    {
        config(['mymate.ping.fail_threshold' => 1]);
        $this->recordingPinger();
        $plain = Device::factory()->create(['mgmt_ip' => '10.0.0.1', 'status' => DeviceStatus::Unknown]);
        $plain2 = Device::factory()->create(['mgmt_ip' => '10.0.0.2', 'status' => DeviceStatus::Unknown]);
        $wan = Device::factory()->create(['mgmt_ip' => '10.0.0.3', 'status' => DeviceStatus::Unknown, 'ping_source' => '203.0.113.9']);
        $wan2 = Device::factory()->create(['mgmt_ip' => '10.0.0.4', 'status' => DeviceStatus::Unknown, 'ping_source' => '203.0.113.9']);
        $other = Device::factory()->create(['mgmt_ip' => '10.0.0.5', 'status' => DeviceStatus::Unknown, 'ping_source' => '198.51.100.7']);

        app(PingFleet::class)();

        $bySource = collect($this->calls->getArrayCopy())->keyBy(fn ($c) => $c['source'] ?? 'default');
        $this->assertCount(3, $this->calls);
        $this->assertSame(['10.0.0.1', '10.0.0.2'], $bySource['default']['ips']);
        $this->assertNull($bySource['default']['source']); // the runner falls back to the global env
        $this->assertSame(['10.0.0.3', '10.0.0.4'], $bySource['203.0.113.9']['ips']);
        $this->assertSame(['10.0.0.5'], $bySource['198.51.100.7']['ips']);

        // Every group's results land - all five devices came up.
        foreach ([$plain, $plain2, $wan, $wan2, $other] as $d) {
            $this->assertSame(DeviceStatus::Up, $d->refresh()->status);
        }
    }

    public function test_no_per_device_source_is_still_a_single_sweep(): void
    {
        $this->recordingPinger();
        Device::factory()->count(3)->create();

        app(PingFleet::class)();

        $this->assertCount(1, $this->calls);
        $this->assertNull($this->calls[0]['source']);
    }

    public function test_a_per_call_source_overrides_the_configured_default(): void
    {
        $args = fn (FpingRunner $r, ?string $source) => (new \ReflectionMethod(FpingRunner::class, 'commandArgs'))->getClosure($r)($source);
        $runner = new FpingRunner(source: '192.0.2.1');

        $withOverride = $args($runner, '203.0.113.9');
        $this->assertSame('203.0.113.9', $withOverride[array_search('-S', $withOverride, true) + 1]);

        $default = $args($runner, null);
        $this->assertSame('192.0.2.1', $default[array_search('-S', $default, true) + 1]);
    }

    public function test_the_device_api_sets_validates_and_clears_the_source(): void
    {
        $this->actingAsUser();
        $device = Device::factory()->create();

        $this->patchJson("/api/devices/{$device->id}", ['ping_source' => '203.0.113.9'])
            ->assertOk()
            ->assertJsonPath('data.ping_source', '203.0.113.9');
        $this->assertSame('203.0.113.9', $device->refresh()->ping_source);

        $this->patchJson("/api/devices/{$device->id}", ['ping_source' => 'eth0'])
            ->assertJsonValidationErrors('ping_source');

        $this->patchJson("/api/devices/{$device->id}", ['ping_source' => null])->assertOk();
        $this->assertNull($device->refresh()->ping_source);
    }

    public function test_an_agent_ping_target_carries_the_source_only_when_set(): void
    {
        $agent = Agent::factory()->create();
        $with = Device::factory()->create(['agent_id' => $agent->id, 'monitored' => true, 'ping_source' => '192.168.88.1']);
        $without = Device::factory()->create(['agent_id' => $agent->id, 'monitored' => true]);

        $job = app(DispatchAgentJobs::class)->buildJob($agent->id);
        $ping = collect($job['poll']['ping'])->keyBy('device_id');

        $this->assertSame('192.168.88.1', $ping[$with->id]['source']);
        $this->assertArrayNotHasKey('source', $ping[$without->id]);
    }
}
