<?php

namespace App\Events;

use App\Events\Concerns\ScopableLiveEvent;
use App\Models\Device;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The metrics poll caught a device's uptime going backwards, ie it restarted (see
 * RecordDeviceResources). Lets the map toast it ("sw1 rebooted (was up 41d)") and an open device
 * page refresh its events timeline, instead of anyone finding out from the uptime graph later.
 *
 * Rare by nature, so one event per reboot, sent inline like the rest (never queued). Scoped the
 * same way DeviceStatusChanged is: a restricted operator only hears about their own devices.
 */
class DeviceRebooted implements ScopableLiveEvent, ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /** Where this copy goes. Null = the shared `map` channel (unrestricted operators). */
    public ?string $channel = null;

    public int $deviceId;

    public string $name;

    public function __construct(Device $device, public string $bootedAt, public ?int $previousUptime)
    {
        // just the two fields, the model itself never goes near the socket
        $this->deviceId = $device->id;
        $this->name = $device->name;
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel($this->channel ?? 'map');
    }

    public function scopedTo(array $visible, string $channel): ?static
    {
        if (! isset($visible[$this->deviceId])) {
            return null;
        }
        $copy = clone $this;
        $copy->channel = $channel;

        return $copy;
    }

    public function broadcastAs(): string
    {
        return 'DeviceRebooted';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'device_id' => $this->deviceId,
            'name' => $this->name,
            'booted_at' => $this->bootedAt,
            // how long it had been up before this, null if we never saw an uptime for it
            'previous_uptime_s' => $this->previousUptime,
        ];
    }
}
