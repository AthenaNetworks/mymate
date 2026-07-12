<?php

namespace App\Events;

use App\Models\Device;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DeviceStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Device $device) {}

    public function broadcastOn(): PrivateChannel
    {
        // Private channel - only authenticated operators (session-authorised) subscribe.
        return new PrivateChannel('map');
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
