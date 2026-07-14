<?php

namespace App\Actions\Polling;

use App\Models\Device;
use App\Models\Sensor;
use App\Services\Snmp\SnmpClient;
use App\Support\DeviceScope;
use App\Support\EngineLog;
use Illuminate\Support\Facades\DB;

/**
 * Poll every enabled custom sensor against the SNMP devices in this shard: GET the OID,
 * scale by the sensor's divisor, upsert the current reading and append a history sample.
 * Best-effort per (sensor, device) - one black-holing OID never sinks the batch.
 */
class PollSensors
{
    public function __construct(private SnmpClient $snmp) {}

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
            $community = $device->credential?->snmp_community;
            if (! $community) {
                continue; // custom sensors are SNMP GETs - skip non-SNMP devices
            }

            foreach ($sensors as $sensor) {
                $inScope = $scopes[$sensor->id];
                if ($inScope !== null && ! isset($inScope[$device->id])) {
                    continue;
                }

                try {
                    $res = $this->snmp->get($device->mgmt_ip, $community, [$sensor->oid]);
                } catch (\Throwable) {
                    continue; // unreachable / unsupported OID - just skip this reading
                }

                $raw = self::firstNumeric($res);
                if ($raw === null) {
                    continue;
                }
                $value = $sensor->divisor != 0.0 ? $raw / $sensor->divisor : $raw;

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

    /** First numeric value in an SNMP GET result (leading signed/decimal number), or null. */
    private static function firstNumeric(array $res): ?float
    {
        foreach ($res as $value) {
            if (preg_match('/-?\d+(\.\d+)?/', (string) $value, $m) === 1) {
                return (float) $m[0];
            }
        }

        return null;
    }
}
