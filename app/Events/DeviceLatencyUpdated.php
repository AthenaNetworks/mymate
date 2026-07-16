<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Ping latency/loss for a latency-history tick, coalesced across devices - one event
 * carries many devices' latest rtt/loss so the internet/upstream card updates live.
 * Mirrors DeviceMetricsUpdated but on the ping latency cadence (~once a minute).
 */
class DeviceLatencyUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  list<array{device_id:int, rtt_ms:?float, loss_pct:?float}>  $devices
     */
    public function __construct(public array $devices) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('map');
    }

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
