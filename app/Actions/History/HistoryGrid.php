<?php

namespace App\Actions\History;

use Illuminate\Support\Carbon;

/**
 * The shared, downsampled time axis a graph draws on: a fixed grid from `from` stepped by a bucket
 * width chosen for ~`history.max_points` points. Deterministic for a given window, so every source
 * (interfaces, sensors, ping, probes) resolved against the same [from, to] lands on the same
 * buckets and can be plotted together and summed (GitHub #28).
 */
class HistoryGrid
{
    /** @return array{bucketSeconds:int, buckets:list<string>, indexOf:array<string,int>} */
    public static function build(Carbon $from, Carbon $to): array
    {
        $maxPoints = max(1, (int) config('mymate.history.max_points', 240));
        $span = max(1, $from->diffInSeconds($to));
        $bucketSeconds = max(10, (int) ceil($span / $maxPoints));
        $bucketCount = (int) ceil($span / $bucketSeconds);

        $buckets = [];
        $indexOf = [];
        for ($i = 0; $i < $bucketCount; $i++) {
            $key = $from->copy()->addSeconds($i * $bucketSeconds)->format('Y-m-d H:i:s');
            $buckets[] = $key;
            $indexOf[$key] = $i;
        }

        return ['bucketSeconds' => $bucketSeconds, 'buckets' => $buckets, 'indexOf' => $indexOf];
    }
}
