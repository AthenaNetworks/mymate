<?php

namespace App\Support;

use App\Console\Commands\LoopCommand;
use App\Services\Backup\RustedClient;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Best-effort snapshot of the moving parts a self-hosted operator cares about -
 * database, Redis, background workers, the WebSocket server, the backup engine and
 * whether the polling loop is actually ticking. Powers the Settings "System status"
 * panel so the common "why isn't X working?" questions are self-diagnosable.
 *
 * Every probe is wrapped so a single sick dependency reports `down` instead of
 * throwing; the whole call is safe to hit from the UI on a poll.
 */
class SystemStatus
{
    /** @return array<int, array{key:string, label:string, status:string, detail:string}> */
    public function check(): array
    {
        return [
            $this->probe('database', 'Database', fn () => DB::connection()->getPdo() ? 'PostgreSQL reachable' : null),
            $this->probe('redis', 'Redis', fn () => Redis::connection()->ping() ? 'Reachable' : null),
            $this->workers(),
            $this->polling(),
            $this->websockets(),
            $this->backups(),
        ];
    }

    /** Run a boolean-ish probe: a returned string is the ok detail, null/false/throw is down. */
    private function probe(string $key, string $label, callable $fn): array
    {
        try {
            $detail = $fn();

            return $detail
                ? $this->row($key, $label, 'ok', (string) $detail)
                : $this->row($key, $label, 'down', 'No response');
        } catch (Throwable $e) {
            return $this->row($key, $label, 'down', $this->trim($e->getMessage()));
        }
    }

    /** Horizon supervisors: at least one master means queue workers are running. */
    private function workers(): array
    {
        try {
            $masters = app(\Laravel\Horizon\Contracts\MasterSupervisorRepository::class)->all();

            return $masters
                ? $this->row('workers', 'Background workers', 'ok', count($masters).' supervisor(s) running')
                : $this->row('workers', 'Background workers', 'down', 'Horizon is not running - queues will not process');
        } catch (Throwable $e) {
            return $this->row('workers', 'Background workers', 'warn', $this->trim($e->getMessage()));
        }
    }

    /**
     * Is the polling loop alive? The loop stamps a heartbeat each tick (the precise signal),
     * but we fall back to real polling activity - a recent history-sample write proves the
     * loop is dispatching and workers are running. That keeps the check honest right after a
     * deploy, when the long-running loop process may not have restarted to pick up the
     * heartbeat code yet, or if the cache was cleared.
     */
    private function polling(): array
    {
        try {
            $limit = max(30, app(Settings::class)->getInt('ping.interval', 5) * 6);
            $ts = (int) Cache::get(LoopCommand::HEARTBEAT_KEY, 0);

            if ($ts > 0 && ($age = now()->timestamp - $ts) <= $limit) {
                return $this->row('polling', 'Polling loop', 'ok', "Last tick {$age}s ago");
            }

            // Heartbeat missing or stale - confirm via actual polling activity.
            $lastSample = $this->lastSampleAt();
            if ($lastSample !== null && ($secs = (int) abs(now()->diffInSeconds($lastSample))) <= max(300, $limit * 4)) {
                return $this->row('polling', 'Polling loop', 'ok', "Polling active (last sample {$secs}s ago)");
            }

            if ($ts === 0 && $lastSample === null) {
                return $this->row('polling', 'Polling loop', 'warn', 'No activity yet - the loop may be starting, or nothing is being polled');
            }

            return $this->row('polling', 'Polling loop', 'down', 'No recent polling activity - the loop looks stopped');
        } catch (Throwable $e) {
            return $this->row('polling', 'Polling loop', 'warn', $this->trim($e->getMessage()));
        }
    }

    /** Freshest history-sample timestamp across the sample tables (last 15 min), or null. */
    private function lastSampleAt(): ?Carbon
    {
        $cutoff = now()->subMinutes(15)->format('Y-m-d H:i:s');
        $latest = null;

        foreach (['interface_samples', 'device_metric_samples', 'ping_samples'] as $table) {
            try {
                $max = DB::table($table)->where('ts', '>=', $cutoff)->max('ts');
            } catch (Throwable) {
                continue; // table may be absent on an older schema
            }
            if ($max !== null) {
                $c = Carbon::parse($max);
                if ($latest === null || $c->greaterThan($latest)) {
                    $latest = $c;
                }
            }
        }

        return $latest;
    }

    /** TCP-connect to the Reverb WebSocket server - live map updates ride on it. */
    private function websockets(): array
    {
        try {
            $host = (string) config('reverb.servers.reverb.host', '127.0.0.1');
            if ($host === '0.0.0.0' || $host === '') {
                $host = '127.0.0.1'; // bind-all -> probe the loopback
            }
            $port = (int) config('reverb.servers.reverb.port', 8080);

            $conn = @fsockopen($host, $port, $errno, $errstr, 1.0);
            if ($conn) {
                fclose($conn);

                return $this->row('websockets', 'WebSocket server', 'ok', "Reverb listening on :{$port}");
            }

            return $this->row('websockets', 'WebSocket server', 'down', "Reverb not reachable on :{$port} ({$errstr})");
        } catch (Throwable $e) {
            return $this->row('websockets', 'WebSocket server', 'warn', $this->trim($e->getMessage()));
        }
    }

    /** The Rusted backup engine: optional, so "not configured" is a warning, not down. */
    private function backups(): array
    {
        try {
            $client = app(RustedClient::class);
            if (! app(\App\Support\BackupSettings::class)->configured()) {
                return $this->row('backups', 'Backup engine', 'warn', 'Not configured (optional)');
            }

            return $client->healthy()
                ? $this->row('backups', 'Backup engine', 'ok', 'Rusted reachable')
                : $this->row('backups', 'Backup engine', 'down', 'Configured but not responding');
        } catch (Throwable $e) {
            return $this->row('backups', 'Backup engine', 'warn', $this->trim($e->getMessage()));
        }
    }

    private function row(string $key, string $label, string $status, string $detail): array
    {
        return ['key' => $key, 'label' => $label, 'status' => $status, 'detail' => $detail];
    }

    private function trim(string $msg): string
    {
        return mb_substr(trim($msg), 0, 160);
    }
}
