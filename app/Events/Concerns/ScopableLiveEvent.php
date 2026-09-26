<?php

namespace App\Events\Concerns;

/**
 * A live-map event that can be narrowed to what one restricted operator is allowed to see.
 *
 * The shared `map` channel carries the whole fleet, so only unrestricted operators may subscribe to
 * it. A restricted operator (per-user, or through a group - GitHub #28) listens on their own
 * `map.user.{id}` channel instead, and {@see \App\Support\LiveBroadcast} sends them a copy of each
 * event holding only their devices. Before this, anyone signed in could subscribe to `map` and read
 * live status for devices outside their maps straight off the websocket.
 */
interface ScopableLiveEvent
{
    /**
     * A copy of this event carrying only the devices in $visible (a device-id => true set), sent to
     * $channel - or null when none of its devices are visible, so nothing is sent at all.
     *
     * @param  array<int, true>  $visible
     */
    public function scopedTo(array $visible, string $channel): ?static;
}
