<?php

namespace App\Jobs;

use App\Actions\Agent\DispatchAgentJobs;
use App\Actions\Devices\CaptureDeviceFacts;
use App\Actions\Polling\DiscoverInterfaces;
use App\Models\Device;
use App\Support\EngineLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Thin queue envelope: (re)discover one device's interfaces, on the `poll`
 * queue. Runs on a slow cadence / on demand - `tries=1` + per-device overlap
 * lock (a longer window than polling, discovery walks more of the MIB).
 */
class DiscoverInterfacesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $deviceId)
    {
        $this->onQueue('poll');
    }

    public function handle(DiscoverInterfaces $discover, CaptureDeviceFacts $facts): void
    {
        $device = Device::find($this->deviceId);
        if ($device?->agent_id !== null) {
            // Behind an agent: this server usually can't reach it (and walking it from here
            // would be the wrong source address anyway), so hand it to the agent (#54).
            DispatchAgentJobs::requestDiscovery($device->agent_id);

            return;
        }
        if ($device !== null) {
            $discover($device);
            $facts($device); // best-effort vendor/model/uptime/type - never throws
        }
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("discover-device-{$this->deviceId}"))->dontRelease()->expireAfter(120)];
    }

    public function failed(Throwable $e): void
    {
        EngineLog::error('discover: job failed', [
            'device_id' => $this->deviceId,
            'exception' => $e::class,
            'error' => $e->getMessage(),
        ]);
    }
}
