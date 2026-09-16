<?php

namespace App\Services\Tools;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The one cache contract every Tools-page run shares. A run is a short-lived streaming
 * job (ping, trace, subnet sweep, port scan) that writes a growing snapshot into Redis;
 * the browser polls it once a second and stops when status leaves 'running'. Nothing
 * about a run touches the database - it all lives in three cache keys per run id:
 *
 *   tool:{id}         the snapshot the frontend polls (envelope below)
 *   tool:{id}:owner   the user id that started it (only they/an admin may stop it)
 *   tool:{id}:stop    a flag the job checks each cycle to cancel itself
 *
 * The envelope is deliberately uniform across kinds so one polling hook and one console
 * component on the frontend can drive all four. `result` is the only kind-specific part.
 */
class ToolRun
{
    /** Snapshots (and the owner/stop flags) live this long before Redis drops them. */
    public const TTL_MINUTES = 15;

    public const COMMAND_TTL_MINUTES = 120;

    public const KINDS = ['ping', 'trace', 'sweep', 'portscan', 'command'];

    /**
     * Seed the "running" envelope and record the owner. Called from the controller before
     * the job is dispatched so the frontend's first poll is a 200, not a 404 race.
     *
     * @param  array<string, mixed>  $result  the kind's initial (usually empty) result payload
     */
    public static function start(string $id, string $kind, string $target, int $ownerId, array $result): void
    {
        $ttl = self::ttlFor($kind);
        self::put($id, $kind, $target, 'running', $result);
        Cache::put(self::ownerKey($id), $ownerId, now()->addMinutes($ttl));
    }

    /**
     * Overwrite the snapshot. Jobs call this on their push cadence with the current result
     * and status. Never touches the owner/stop keys - a snapshot rewrite must not clear them.
     *
     * @param  array<string, mixed>  $result
     */
    public static function put(string $id, string $kind, string $target, string $status, array $result, ?string $error = null): void
    {
        Cache::put(self::key($id), [
            'run_id' => $id,
            'kind' => $kind,
            'target' => $target,
            'status' => $status,
            'error' => $error,
            'result' => $result,
        ], now()->addMinutes(self::ttlFor($kind)));
    }

    /**
     * Atomically mutate a run snapshot. Concurrent per-device jobs use this so one result
     * cannot overwrite another device's status.
     *
     * @param callable(array<string,mixed>): array<string,mixed> $mutator
     */
    public static function update(string $id, callable $mutator): void
    {
        $lock = Cache::lock("tool:{$id}:update", 10);
        try {
            $lock->block(5);
        } catch (Throwable) {
            return;
        }

        try {
            $snapshot = self::get($id);
            if ($snapshot === null) {
                return;
            }
            $snapshot['result'] = $mutator((array) ($snapshot['result'] ?? []));
            $status = ($snapshot['result']['complete'] ?? false) ? 'done' : (string) $snapshot['status'];
            self::put($id, (string) $snapshot['kind'], (string) $snapshot['target'], $status, (array) $snapshot['result'], $snapshot['error'] ?? null);
        } catch (Throwable) {
            // A short-lived status cache must never make the worker fail after the SSH run.
        } finally {
            $lock->release();
        }
    }

    /** @return array<string, mixed>|null */
    public static function get(string $id): ?array
    {
        return Cache::get(self::key($id));
    }

    public static function owner(string $id): ?int
    {
        $owner = Cache::get(self::ownerKey($id));

        return $owner === null ? null : (int) $owner;
    }

    /** Ask a running job to cancel. It notices within one poll cycle and writes a final snapshot. */
    public static function requestStop(string $id): void
    {
        $snapshot = self::get($id);
        $ttl = self::ttlFor((string) ($snapshot['kind'] ?? ''));
        Cache::put(self::stopKey($id), true, now()->addMinutes($ttl));
    }

    public static function stopRequested(string $id): bool
    {
        return (bool) Cache::get(self::stopKey($id));
    }

    public static function clearStop(string $id): void
    {
        Cache::forget(self::stopKey($id));
    }

    private static function ttlFor(string $kind): int
    {
        return $kind === 'command' ? self::COMMAND_TTL_MINUTES : self::TTL_MINUTES;
    }

    private static function key(string $id): string
    {
        return "tool:{$id}";
    }

    private static function ownerKey(string $id): string
    {
        return "tool:{$id}:owner";
    }

    private static function stopKey(string $id): string
    {
        return "tool:{$id}:stop";
    }
}
