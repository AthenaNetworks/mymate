<?php

namespace App\Actions\History;

use Illuminate\Support\Carbon;

/**
 * Bucketed/downsampled ping latency history for one device over [from, to). Mirrors
 * GetDeviceMetricSamples: the bucket width targets ~`history.max_points` points, each
 * bucket averaging rtt/loss/jitter, from raw or rollups by window (see HistoryQuery).
 * Averaging binary per-sweep loss (0/100) across a bucket yields a real packet-loss
 * percentage, and the rollups keep that exact since they average by sample count.
 */
class GetPingSamples
{
    public function __construct(private readonly HistoryQuery $history) {}

    /** @return list<array{ts:string, rtt_ms:?float, loss_pct:?float, jitter_ms:?float}> */
    public function __invoke(int $deviceId, Carbon $from, Carbon $to): array
    {
        $rows = $this->history->rows(
            'ping', HistoryGrid::build($from, $to), $to,
            ['rtt_ms' => ['avg'], 'loss_pct' => ['avg'], 'jitter_ms' => ['avg']],
            'device_id = ?', [$deviceId],
        );

        return array_map(static fn ($r): array => [
            'ts' => $r->bucket,
            'rtt_ms' => self::num($r->rtt_ms, 2),
            'loss_pct' => self::num($r->loss_pct, 1),
            'jitter_ms' => self::num($r->jitter_ms, 2),
        ], $rows);
    }

    private static function num(mixed $value, int $precision): ?float
    {
        return $value === null ? null : round((float) $value, $precision);
    }
}
