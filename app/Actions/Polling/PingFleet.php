<?php

namespace App\Actions\Polling;

use App\Actions\Outages\RecordOutage;
use App\Enums\DeviceStatus;
use App\Events\DeviceStatusChanged;
use App\Models\Device;
use App\Services\Ping\Pinger;
use App\Support\EngineLog;

/**
 * One up/down sweep: ping every device's mgmt_ip in a single batch, then flip
 * status only for devices whose reachability changed (stamping last_change and
 * broadcasting). Returns the number of devices that changed.
 */
class PingFleet
{
    public function __construct(private Pinger $pinger, private RecordOutage $outages) {}

    public function __invoke(): int
    {
        // Skip monitoring-paused devices (monitored=false -> mock/demo) and agent-assigned
        // devices (agent_id set -> pinged by their remote agent, not from here).
        $devices = Device::where('monitored', true)->whereNull('agent_id')->get();

        if ($devices->isEmpty()) {
            return 0;
        }

        $reachable = array_flip($this->pinger->reachable($devices->pluck('mgmt_ip')->all()));

        $changed = 0;
        foreach ($devices as $device) {
            $new = isset($reachable[$device->mgmt_ip]) ? DeviceStatus::Up : DeviceStatus::Down;

            if ($device->status !== $new) {
                $device->status = $new;
                $device->last_change = now();
                $device->save();

                // Log the outage window: open on down, close on recovery.
                $new === DeviceStatus::Down ? $this->outages->open($device) : $this->outages->close($device);

                DeviceStatusChanged::dispatch($device);
                $changed++;
            }
        }

        EngineLog::debug('ping: sweep complete', [
            'total' => $devices->count(),
            'reachable' => count($reachable),
            'changed' => $changed,
        ]);

        return $changed;
    }
}
