<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Who gets a scoped copy of each live-map event, and which devices they may see.
 *
 * Restricted operators (per-user or via a group, GitHub #28) can't subscribe to the shared `map`
 * channel, so {@see LiveBroadcast} sends each of them a copy filtered to their own devices on
 * `map.user.{id}`. Resolving visibility per user on every poll tick would be a query storm, so the
 * set is cached briefly: a change to someone's maps or groups reaches their live stream within
 * {@see self::TTL} seconds (their page loads and API reads are always exact - this is only the
 * push stream).
 */
class RestrictedAudience
{
    private const KEY = 'live:restricted-audience';

    private const TTL = 60;

    /** @return array<int, array<int, true>> user id => visible device-id set */
    public static function members(): array
    {
        return Cache::remember(self::KEY, self::TTL, static function (): array {
            $out = [];
            foreach (User::where('is_admin', false)->get() as $user) {
                if ($user->isRestricted()) {
                    $out[$user->id] = array_fill_keys($user->visibleDeviceIds(), true);
                }
            }

            return $out;
        });
    }

    /** Drop the cached audience, e.g. after a user or group's access changes. */
    public static function forget(): void
    {
        Cache::forget(self::KEY);
    }

    public static function channelFor(int $userId): string
    {
        return "map.user.{$userId}";
    }
}
