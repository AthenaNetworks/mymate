<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Structured logging for the monitoring engine (pollers, discovery, the loop
 * daemon, jobs). Writes to the configurable `mymate.log_channel` so there's one
 * place to tail for "what's the engine doing / what failed".
 *
 * Conventions:
 *  - message is a short, greppable, prefixed string ("poll: device poll failed").
 *  - context is a flat array of scalars (device_id, ip, method, exception, error...).
 *  - NEVER pass credentials / SNMP communities into the context.
 */
class EngineLog
{
    /** @param array<string, mixed> $context */
    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public static function debug(string $message, array $context = []): void
    {
        self::write('debug', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public static function warning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    /**
     * A logging failure (e.g. a daily-rotated file owned by a different process/user than
     * the one currently handling this request - the `mymate` channel's file is written by
     * both root-run Horizon/loop workers and the php-fpm web user, and whichever creates a
     * given day's file first sets its owner/permissions) must never take down an otherwise-
     * successful request. Fall back to the default channel; if that also fails, drop it -
     * logging is diagnostic, not part of the request's correctness.
     *
     * @param  array<string, mixed>  $context
     */
    private static function write(string $level, string $message, array $context): void
    {
        try {
            Log::channel(config('mymate.log_channel', 'mymate'))->{$level}($message, $context);
        } catch (\Throwable $e) {
            try {
                Log::{$level}($message, [...$context, 'engine_log_channel_error' => $e->getMessage()]);
            } catch (\Throwable) {
                // Truly nowhere left to log this - swallow rather than fail the request.
            }
        }
    }
}
