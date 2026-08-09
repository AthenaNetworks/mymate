<?php

namespace App\Services\Tools;

/**
 * Absolute-path resolution for the external binaries the Tools jobs shell out to. Same
 * reasoning as FpingRunner's own locator: a queue worker (and php-fpm) often runs with a
 * PATH that omits /usr/local/sbin, where the from-source fping >=5.5 lives, so resolving
 * by absolute path is the difference between a working tool and a silently empty result.
 */
class Binaries
{
    public static function fping(): string
    {
        return self::resolve(
            config('mymate.ping.fping'),
            ['/usr/local/sbin/fping', '/usr/sbin/fping', '/usr/local/bin/fping', '/usr/bin/fping'],
            'fping',
        );
    }

    public static function mtr(): string
    {
        return self::resolve(
            config('mymate.trace.mtr'),
            ['/usr/bin/mtr', '/usr/sbin/mtr', '/usr/local/bin/mtr', '/usr/local/sbin/mtr'],
            'mtr',
        );
    }

    public static function ping(): string
    {
        return self::resolve(null, ['/bin/ping', '/usr/bin/ping'], 'ping');
    }

    /**
     * @param  mixed  $configured  an explicit config path (used if executable), else null
     * @param  list<string>  $candidates  common absolute locations, tried in order
     * @param  string  $fallback  bare command name, resolved against PATH as a last resort
     */
    private static function resolve(mixed $configured, array $candidates, string $fallback): string
    {
        if (is_string($configured) && $configured !== '' && is_executable($configured)) {
            return $configured;
        }
        foreach ($candidates as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        return $fallback;
    }
}
