<?php

namespace App\Actions\Outages;

use App\Models\Device;
use App\Models\Outage;

/**
 * Record device down->up events, driven off the up/down sweep.
 * Idempotent: at most one open outage per device.
 */
class RecordOutage
{
    /** A device just went down -> open an outage (reuse an already-open one). */
    public function open(Device $device): void
    {
        Outage::firstOrCreate(
            ['device_id' => $device->id, 'ended_at' => null],
            ['started_at' => now(), 'cause' => 'unreachable'],
        );
    }

    /** A device recovered -> close its open outage, stamping the duration. */
    public function close(Device $device): void
    {
        $open = Outage::where('device_id', $device->id)->whereNull('ended_at')->latest('started_at')->first();
        if ($open === null) {
            return;
        }

        $open->ended_at = now();
        $open->duration_s = (int) $open->started_at->diffInSeconds($open->ended_at);
        $open->save();
    }
}
