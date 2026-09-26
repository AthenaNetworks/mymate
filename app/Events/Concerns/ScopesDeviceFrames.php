<?php

namespace App\Events\Concerns;

use Illuminate\Broadcasting\PrivateChannel;

/**
 * {@see ScopableLiveEvent} for the batched live events, whose payload is a public `$devices` list of
 * per-device frames keyed by `device_id` (util, metrics, latency). A scoped copy keeps only the
 * frames for visible devices and goes to the operator's own channel instead of the shared one.
 */
trait ScopesDeviceFrames
{
    /** Where this copy goes. Null = the shared `map` channel (unrestricted operators). */
    public ?string $channel = null;

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel($this->channel ?? 'map');
    }

    public function scopedTo(array $visible, string $channel): ?static
    {
        $frames = array_values(array_filter(
            $this->devices,
            static fn (array $frame): bool => isset($visible[(int) ($frame['device_id'] ?? 0)]),
        ));
        if ($frames === []) {
            return null;
        }

        $copy = clone $this;
        $copy->devices = $frames;
        $copy->channel = $channel;

        return $copy;
    }
}
