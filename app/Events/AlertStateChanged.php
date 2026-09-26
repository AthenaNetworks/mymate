<?php

namespace App\Events;

use App\Events\Concerns\ScopableLiveEvent;
use App\Models\AlertEvent;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An alert just started firing, or a firing one resolved (GitHub #22). Lets the map screen show a
 * port going down the way it shows a device outage - a popup and a live count - without anyone
 * opening the Alerts page. Sent from AlertEvent's own lifecycle, so whatever the alert policy
 * decides to fire on (e.g. only uplinks) is exactly what surfaces here.
 *
 * Carries the device (and interface, for a port alert) parsed out of the dedupe key, which is what
 * lets a restricted operator's copy be scoped to their devices. An alert with no device in its key
 * (a remote agent going offline) goes to unrestricted operators only.
 */
class AlertStateChanged implements ScopableLiveEvent, ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /** Where this copy goes. Null = the shared `map` channel (unrestricted operators). */
    public ?string $channel = null;

    public ?int $deviceId;

    public ?int $interfaceId;

    /** @param  'firing'|'resolved'  $state */
    public function __construct(public AlertEvent $event, public string $state, public ?string $condition)
    {
        // Keys look like device:12, device:12:iface:40, device:12:iface:40:optical:rx, agent:3 ...
        $this->deviceId = preg_match('/^device:(\d+)/', $event->dedupe_key, $m) ? (int) $m[1] : null;
        $this->interfaceId = preg_match('/:iface:(\d+)/', $event->dedupe_key, $m) ? (int) $m[1] : null;
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel($this->channel ?? 'map');
    }

    public function broadcastAs(): string
    {
        return 'AlertStateChanged';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->event->id,
            'state' => $this->state,
            'condition' => $this->condition,
            'message' => $this->event->message,
            'device_id' => $this->deviceId,
            'interface_id' => $this->interfaceId,
        ];
    }

    public function scopedTo(array $visible, string $channel): ?static
    {
        if ($this->deviceId === null || ! isset($visible[$this->deviceId])) {
            return null;
        }
        $copy = clone $this;
        $copy->channel = $channel;

        return $copy;
    }
}
