<?php

namespace App\Actions\Polling;

use App\Models\Device;
use App\Models\Sensor;
use App\Support\DeviceScope;
use App\Support\EngineLog;
use App\Support\SensorReader;
use Illuminate\Support\Facades\DB;

/**
 * Poll every enabled custom sensor against the SNMP devices in this shard: GET the OID,
 * scale by the sensor's divisor, upsert the current reading and append a history sample.
 * Best-effort per (sensor, device) - one black-holing OID never sinks the batch.
 */
class PollSensors
{
    public function __construct(private SensorReader $reader) {}

    /** @param  list<int>  $deviceIds */
    public function __invoke(array $deviceIds): void
    {
        $sensors = Sensor::where('enabled', true)->get();
        if ($sensors->isEmpty() || $deviceIds === []) {
            return;
        }

        // Resolve each sensor's scope to a device-id set once (null = all devices).
        $scopes = [];
        foreach ($sensors as $sensor) {
            $ids = DeviceScope::resolve($sensor->scope);
            $scopes[$sensor->id] = $ids === null ? null : array_flip($ids);
        }

        $devices = Device::with('credential')->whereIn('id', $deviceIds)->get();
        $now = now();
        $readings = [];
        $history = [];

        foreach ($devices as $device) {
            $community = \App\Services\Snmp\SnmpCredential::fromCredential($device->credential);
            if (! $community->isUsable()) {
                continue; // custom sensors are SNMP GETs - skip non-SNMP devices
            }

            foreach ($sensors as $sensor) {
                $inScope = $scopes[$sensor->id];
                if ($inScope !== null && ! isset($inScope[$device->id])) {
                    continue;
                }

                try {
                    $value = $this->reader->read($device->mgmt_ip, $community, $sensor->oid, $sensor->mode, $sensor->agg, (float) $sensor->divisor);
                } catch (\Throwable) {
                    continue; // unreachable / unsupported OID - just skip this reading
                }

                if ($value === null) {
                    continue;
                }

                $readings[] = ['sensor_id' => $sensor->id, 'device_id' => $device->id, 'value' => $value, 'read_at' => $now];
                $history[] = ['sensor_id' => $sensor->id, 'device_id' => $device->id, 'ts' => $now, 'value' => $value];
            }
        }

        if ($readings !== []) {
            DB::table('sensor_readings')->upsert($readings, ['sensor_id', 'device_id'], ['value', 'read_at']);
        }
        if ($history !== [] && config('mymate.history.enabled', true)) {
            try {
                DB::table('sensor_samples')->insert($history);
            } catch (\Throwable $e) {
                EngineLog::warning('sensors: history write failed', ['rows' => count($history), 'error' => $e->getMessage()]);
            }
        }
    }

}
