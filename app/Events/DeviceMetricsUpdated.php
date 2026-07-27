<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Device resource metrics (cpu/mem/temp) for a poll tick, coalesced across devices -
 * one event carries many devices' latest readings so the map tiles update live without
 * a message per device. Mirrors InterfaceUtilUpdated but on the slower metrics cadence.
 */
class DeviceMetricsUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  list<array{device_id:int, cpu_pct:?float, mem_used_pct:?float, temp_c:?float}>  $devices
     */
    public function __construct(public array $devices) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('map');
    }

    public function broadcastAs(): string
    {
        return 'DeviceMetricsUpdated';
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
