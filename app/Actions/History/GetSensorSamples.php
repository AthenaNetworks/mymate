<?php

namespace App\Actions\History;

use Illuminate\Support\Carbon;

/**
 * Bucketed/downsampled history for one custom sensor on one device over [from, to).
 * Mirrors GetDeviceMetricSamples: ~`history.max_points` buckets, raw or rollups by window.
 */
class GetSensorSamples
{
    public function __construct(private readonly HistoryQuery $history) {}

    /** @return list<array{ts:string, value:?float}> */
    public function __invoke(int $sensorId, int $deviceId, Carbon $from, Carbon $to): array
    {
        $rows = $this->history->rows(
            'sensor', HistoryGrid::build($from, $to), $to, ['value' => ['avg']],
            'sensor_id = ? AND device_id = ?', [$sensorId, $deviceId],
        );

        return array_map(static fn ($r): array => [
            'ts' => $r->bucket,
            'value' => $r->value === null ? null : round((float) $r->value, 3),
        ], $rows);
    }
}
