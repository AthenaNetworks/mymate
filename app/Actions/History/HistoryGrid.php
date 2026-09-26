<?php

namespace App\Actions\History;

use Illuminate\Support\Carbon;

/**
 * The shared, downsampled time axis a graph draws on: a fixed grid from `from` stepped by a bucket
 * width chosen for ~`history.max_points` points. Deterministic for a given window, so every source
 * (interfaces, sensors, ping, probes) resolved against the same [from, to] lands on the same
 * buckets and can be plotted together and summed (GitHub #28).
 *
 * The width also decides which storage tier the window reads (see HistoryTiers::plan). For a
 * rollup tier the origin is snapped back to a tier boundary, so `origin` can sit a little before
 * `from`, and every query should bucket from `origin`.
 */
class HistoryGrid
{
    /** @return array{bucketSeconds:int, origin:Carbon, tier:string, buckets:list<string>, indexOf:array<string,int>} */
    public static function build(Carbon $from, Carbon $to): array
    {
        $plan = app(HistoryTiers::class)->plan($from, $to);
        $origin = $plan['origin'];
        $bucketSeconds = $plan['bucketSeconds'];
        $span = max(1, $origin->diffInSeconds($to));
        $bucketCount = (int) ceil($span / $bucketSeconds);

        $buckets = [];
        $indexOf = [];
        for ($i = 0; $i < $bucketCount; $i++) {
            $key = $origin->copy()->addSeconds($i * $bucketSeconds)->format('Y-m-d H:i:s');
            $buckets[] = $key;
            $indexOf[$key] = $i;
        }

        return ['bucketSeconds' => $bucketSeconds, 'origin' => $origin, 'tier' => $plan['tier'], 'buckets' => $buckets, 'indexOf' => $indexOf];
    }
}
