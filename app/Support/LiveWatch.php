<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Devices someone has open right now (the device page, or the inspector on the map).
 *
 * The live util stream only carries link-bound interfaces, that's all the map can colour and it
 * keeps the per-tick broadcast small on a big fleet. A device page wants every port though, so
 * while one is open it pings {@see self::touch()} every minute and the poller adds that device's
 * other ports to its frame (as `ports`, see PollInterfaces). Nobody looking = nothing extra sent.
 *
 * One small cache key per device with a TTL, so a closed tab just expires. The poller reads the
 * whole batch in one go ({@see self::watched()}), a single MGET per tick.
 */
class LiveWatch
{
    /** A bit over two heartbeats, so one late ping doesn't drop the device. */
    public const TTL = 150;

    private const PREFIX = 'live:watch:';

    public static function touch(int $deviceId): void
    {
        Cache::put(self::PREFIX.$deviceId, true, self::TTL);
    }

    /**
     * Which of these devices are being watched.
     *
     * @param  list<int>  $deviceIds
     * @return array<int, true>
     */
    public static function watched(array $deviceIds): array
    {
        if ($deviceIds === []) {
            return [];
        }
        try {
            $hits = Cache::many(array_map(static fn (int $id): string => self::PREFIX.$id, $deviceIds));
        } catch (\Throwable $e) {
            // a cache hiccup costs the extra ports for a tick, never the poll
            EngineLog::warning('broadcast: live watch list unavailable', ['error' => $e->getMessage()]);

            return [];
        }

        $out = [];
        foreach ($deviceIds as $id) {
            if (! empty($hits[self::PREFIX.$id])) {
                $out[$id] = true;
            }
        }

        return $out;
    }
}
