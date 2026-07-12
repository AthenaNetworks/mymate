<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Per-interface utilisation for a poll tick, **coalesced across devices** (
 * scale-out): one event carries many devices' frames so thousands of devices don't
 * mean thousands of WS messages. Broadcast every tick (unlike status, which is
 * change-only). The frontend colour ramp folds these frames onto edges.
 */
class InterfaceUtilUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  list<array{device_id:int, status:string, interfaces:list<array<string,mixed>>}>  $devices
     */
    public function __construct(public array $devices) {}

    public function broadcastOn(): PrivateChannel
    {
        // Private channel - session-authorised operators only.
        return new PrivateChannel('map');
    }

    public function broadcastAs(): string
    {
        return 'InterfaceUtilUpdated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'devices' => $this->devices,
            'device_count' => count($this->devices),
            'interface_count' => array_sum(array_map(
                static fn (array $d): int => count($d['interfaces']),
                $this->devices,
            )),
        ];
    }
}
