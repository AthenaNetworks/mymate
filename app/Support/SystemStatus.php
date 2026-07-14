<?php

namespace App\Support;

use App\Console\Commands\LoopCommand;
use App\Services\Backup\RustedClient;
use App\Support\Settings;
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

    /** The polling loop stamps a heartbeat each tick; stale = the loop is stopped/stuck. */
    private function polling(): array
    {
        try {
            $ts = (int) Cache::get(LoopCommand::HEARTBEAT_KEY, 0);
            if ($ts === 0) {
                return $this->row('polling', 'Polling loop', 'warn', 'No heartbeat yet - the loop may be starting');
            }

            $age = now()->timestamp - $ts;
            // The loop ticks on the ping interval (a few seconds); allow generous slack.
            $limit = max(30, app(Settings::class)->getInt('ping.interval', 5) * 6);
            if ($age <= $limit) {
                return $this->row('polling', 'Polling loop', 'ok', "Last tick {$age}s ago");
            }

            return $this->row('polling', 'Polling loop', 'down', "No tick for {$age}s - up/down monitoring is stalled");
        } catch (Throwable $e) {
            return $this->row('polling', 'Polling loop', 'warn', $this->trim($e->getMessage()));
        }
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
