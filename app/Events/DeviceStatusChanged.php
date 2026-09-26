<?php

namespace App\Events;

use App\Events\Concerns\ScopableLiveEvent;
use App\Models\Device;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DeviceStatusChanged implements ShouldBroadcastNow, ScopableLiveEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** Where this copy goes. Null = the shared `map` channel (unrestricted operators). */
    public ?string $channel = null;

    public function __construct(public Device $device) {}

    public function broadcastOn(): PrivateChannel
    {
        // Private channel - only authenticated operators (session-authorised) subscribe. A
        // restricted operator gets their own scoped copy instead (see ScopableLiveEvent).
        return new PrivateChannel($this->channel ?? 'map');
    }

    public function scopedTo(array $visible, string $channel): ?static
    {
        if (! isset($visible[$this->device->id])) {
            return null;
        }
        $copy = clone $this;
        $copy->channel = $channel;

        return $copy;
    }

    public function broadcastAs(): string
    {
        return 'DeviceStatusChanged';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->device->id,
            'status' => $this->device->status->value,
            'last_change' => $this->device->last_change?->toIso8601String(),
        ];
    }
}
