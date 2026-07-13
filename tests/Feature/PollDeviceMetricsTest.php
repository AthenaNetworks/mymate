<?php

namespace Tests\Feature;

use App\Actions\Polling\PollDeviceMetrics;
use App\Enums\PollMethod;
use App\Events\DeviceMetricsUpdated;
use App\Models\Device;
use App\Services\Polling\DeviceMetrics;
use App\Services\Polling\DeviceMetricsDriver;
use App\Services\Polling\DeviceMetricsDriverFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class PollDeviceMetricsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Fake factory: returns cpu=12/mem=34/temp=56 for every device, EXCEPT a device
     * named "BAD" (throws - failure-isolation probe) and "EMPTY" (all-null reading).
     */
    private function bindFakeDriver(): void
    {
        $driver = new class implements DeviceMetricsDriver
        {
            public function sample(Device $device): DeviceMetrics
            {
                if ($device->name === 'BAD') {
                    throw new \RuntimeException('snmp boom');
                }
                if ($device->name === 'EMPTY') {
                    return new DeviceMetrics;
                }

                return new DeviceMetrics(cpuPct: 12.0, memUsedPct: 34.0, tempC: 56.0);
            }
        };

        $factory = new class($driver) extends DeviceMetricsDriverFactory
        {
            public function __construct(private DeviceMetricsDriver $driver) {}

            public function for(Device $device): DeviceMetricsDriver
            {
                return $this->driver;
            }
        };

        $this->app->instance(DeviceMetricsDriverFactory::class, $factory);
    }

    private function device(string $name): Device
    {
        return Device::factory()->create(['poll_method' => PollMethod::Snmp, 'name' => $name]);
    }

    public function test_writes_latest_values_history_and_broadcasts(): void
    {
        Event::fake([DeviceMetricsUpdated::class]);
        $this->bindFakeDriver();
        $device = $this->device('rtr1');

        $count = app(PollDeviceMetrics::class)([$device->id]);

        $this->assertSame(1, $count);
        $device->refresh();
        $this->assertSame(12.0, $device->cpu_pct);
        $this->assertSame(34.0, $device->mem_used_pct);
        $this->assertSame(56.0, $device->temp_c);
        $this->assertNotNull($device->metrics_at);

        $this->assertSame(1, DB::table('device_metric_samples')->where('device_id', $device->id)->count());
        Event::assertDispatched(DeviceMetricsUpdated::class, fn ($e) => $e->devices[0]['device_id'] === $device->id
            && $e->devices[0]['cpu_pct'] === 12.0);
    }

    public function test_one_failing_device_does_not_sink_the_batch(): void
    {
        Event::fake([DeviceMetricsUpdated::class]);
        $this->bindFakeDriver();
        $bad = $this->device('BAD');
        $good = $this->device('good');

        $count = app(PollDeviceMetrics::class)([$bad->id, $good->id]);

        $this->assertSame(1, $count); // only the good one produced a reading
        $this->assertNull($bad->refresh()->cpu_pct);
        $this->assertSame(12.0, $good->refresh()->cpu_pct);
    }

    public function test_all_null_reading_is_skipped_no_history_no_stamp(): void
    {
        Event::fake([DeviceMetricsUpdated::class]);
        $this->bindFakeDriver();
        $device = $this->device('EMPTY');

        $count = app(PollDeviceMetrics::class)([$device->id]);

        $this->assertSame(0, $count);
        $this->assertNull($device->refresh()->metrics_at);
        $this->assertSame(0, DB::table('device_metric_samples')->count());
        Event::assertNotDispatched(DeviceMetricsUpdated::class);
    }
}
