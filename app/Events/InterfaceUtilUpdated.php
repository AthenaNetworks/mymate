<?php

namespace App\Events;

use App\Events\Concerns\ScopableLiveEvent;
use App\Events\Concerns\ScopesDeviceFrames;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Per-interface utilisation for a poll tick, **coalesced across devices** (
 * scale-out): one event carries many devices' frames so thousands of devices don't
 * mean thousands of WS messages. Broadcast every tick (unlike status, which is
 * change-only). The frontend colour ramp folds these frames onto edges.
 *
 * Each interface frame can also carry the port-list extras (oper_status, pkts / errors /
 * discards rates, optical Rx/Tx), only when they changed - see LiveInterfaceFrame. A device
 * someone has open also gets its non-link interfaces as `ports`, which the map ignores and
 * the port lists patch from (see PollInterfaces::narrowToLinkedInterfaces).
 */
class InterfaceUtilUpdated implements ScopableLiveEvent, ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, ScopesDeviceFrames, SerializesModels;

    /**
     * @param  list<array{device_id:int, status:string, interfaces:list<array<string,mixed>>, ports?:list<array<string,mixed>>}>  $devices
     */
    public function __construct(public array $devices) {}

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
                static fn (array $d): int => count($d['interfaces']) + count($d['ports'] ?? []),
                $this->devices,
            )),
        ];
    }
}
