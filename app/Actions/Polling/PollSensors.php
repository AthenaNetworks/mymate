<?php

namespace App\Actions\Polling;

use App\Models\Device;
use App\Models\Sensor;
use App\Services\Snmp\SnmpCredential;
use App\Support\DeviceScope;
use App\Support\EngineLog;
use App\Support\SensorReader;
use Illuminate\Support\Collection;
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

        $scopes = self::scopes($sensors);
        $devices = Device::with('credential')->whereIn('id', $deviceIds)->get();
        $readings = [];

        foreach ($devices as $device) {
            $community = SnmpCredential::fromCredential($device->credential);
            if (! $community->isUsable()) {
                continue; // custom sensors are SNMP GETs - skip non-SNMP devices
            }

            foreach (self::forDevice($sensors, $scopes, $device->id) as $sensor) {
                try {
                    $value = $this->reader->read($device->mgmt_ip, $community, $sensor->oid, $sensor->mode, $sensor->agg, (float) $sensor->divisor);
                } catch (\Throwable) {
                    continue; // unreachable / unsupported OID - just skip this reading
                }

                if ($value !== null) {
                    $readings[] = ['sensor_id' => $sensor->id, 'device_id' => $device->id, 'value' => $value];
                }
            }
        }

        self::store($readings);
    }

    /**
     * Each sensor's scope resolved to a device-id set once (null = all devices).
     *
     * @param  Collection<int, Sensor>  $sensors
     * @return array<int, array<int, int>|null>
     */
    public static function scopes(Collection $sensors): array
    {
        $scopes = [];
        foreach ($sensors as $sensor) {
            $ids = DeviceScope::resolve($sensor->scope);
            $scopes[$sensor->id] = $ids === null ? null : array_flip($ids);
        }

        return $scopes;
    }

    /**
     * The sensors whose scope covers this device.
     *
     * @param  Collection<int, Sensor>  $sensors
     * @param  array<int, array<int, int>|null>  $scopes
     * @return list<Sensor>
     */
    public static function forDevice(Collection $sensors, array $scopes, int $deviceId): array
    {
        return $sensors->filter(function (Sensor $sensor) use ($scopes, $deviceId): bool {
            $inScope = $scopes[$sensor->id] ?? null;

            return $inScope === null || isset($inScope[$deviceId]);
        })->values()->all();
    }

    /**
     * Upsert the current readings and append history. Shared with remote-agent sensor reads (#51).
     *
     * @param  list<array{sensor_id: int, device_id: int, value: float}>  $readings
     */
    public static function store(array $readings): void
    {
        if ($readings === []) {
            return;
        }
        $now = now();
        DB::table('sensor_readings')->upsert(
            array_map(fn (array $r): array => $r + ['read_at' => $now], $readings),
            ['sensor_id', 'device_id'], ['value', 'read_at'],
        );
        if (config('mymate.history.enabled', true)) {
            try {
                DB::table('sensor_samples')->insert(array_map(fn (array $r): array => [
                    'sensor_id' => $r['sensor_id'], 'device_id' => $r['device_id'], 'ts' => $now, 'value' => $r['value'],
                ], $readings));
            } catch (\Throwable $e) {
                EngineLog::warning('sensors: history write failed', ['rows' => count($readings), 'error' => $e->getMessage()]);
            }
        }
    }
}
