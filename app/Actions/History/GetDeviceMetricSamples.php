<?php

namespace App\Actions\History;

use Illuminate\Support\Carbon;

/**
 * Bucketed/downsampled cpu/mem/temp history for one device over [from, to). Mirrors
 * GetInterfaceSamples: the bucket width targets ~`history.max_points` points regardless
 * of window, each bucket averaged, from raw samples or the rollups (see HistoryQuery).
 */
class GetDeviceMetricSamples
{
    private const METRICS = [
        'cpu_pct' => 2, 'mem_used_pct' => 2, 'temp_c' => 1, 'signal_dbm' => 1,
        'snr_db' => 1, 'ccq_pct' => 1, 'wireless_clients' => 0, 'ospf_neighbors' => 0,
    ];

    public function __construct(private readonly HistoryQuery $history) {}

    /** @return list<array<string, string|float|null>> */
    public function __invoke(int $deviceId, Carbon $from, Carbon $to): array
    {
        $rows = $this->history->rows(
            'device_metric', HistoryGrid::build($from, $to), $to,
            array_map(static fn () => ['avg'], self::METRICS),
            'device_id = ?', [$deviceId],
        );

        return array_map(static function ($r): array {
            $out = ['ts' => $r->bucket];
            foreach (self::METRICS as $metric => $precision) {
                $out[$metric] = $r->{$metric} === null ? null : round((float) $r->{$metric}, $precision);
            }

            return $out;
        }, $rows);
    }
}
