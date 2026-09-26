<?php

namespace App\Events;

use App\Events\Concerns\ScopableLiveEvent;
use App\Events\Concerns\ScopesDeviceFrames;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Ping latency/loss for a latency-history tick, coalesced across devices - one event
 * carries many devices' latest rtt/loss so the internet/upstream card updates live.
 * Mirrors DeviceMetricsUpdated but on the ping latency cadence (~once a minute).
 */
class DeviceLatencyUpdated implements ShouldBroadcastNow, ScopableLiveEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels, ScopesDeviceFrames;

    /**
     * @param  list<array{device_id:int, rtt_ms:?float, loss_pct:?float}>  $devices
     */
    public function __construct(public array $devices) {}


    public function broadcastAs(): string
    {
        return 'DeviceLatencyUpdated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'devices' => $this->devices,
            'device_count' => count($this->devices),
        ];
    }
}
