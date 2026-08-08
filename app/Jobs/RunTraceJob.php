<?php

namespace App\Jobs;

use App\Services\Trace\MtrRawParser;
use App\Support\EngineLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Runs one live `mtr --raw` trace to a device's mgmt IP and streams hop stats into
 * `Cache::get("trace:{runId}")` for TraceController::show() to poll. Its own queue
 * (`trace`) keeps a long-running interactive trace off `poll`/`ping` - an operator
 * staring at a live table must never be the reason a metrics sweep stalls. `tries=1`:
 * a killed/failed trace is surfaced as status=failed/stopped, never silently retried
 * into a run the operator already closed.
 */
class RunTraceJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 280;

    public int $tries = 1;

    /** How often a fresh snapshot is written to cache while the trace is running. */
    private const PUSH_INTERVAL_SECONDS = 1.0;

    /** Poll cadence for new process output and the stop flag. */
    private const POLL_INTERVAL_MICROS = 250_000;

    /** How long a run's snapshot survives in cache once written. */
    private const CACHE_TTL_MINUTES = 15;

    public function __construct(
        public int $deviceId,
        public string $runId,
        public string $ip,
        public int $rounds,
    ) {
        $this->onQueue('trace');
    }

    public function handle(): void
    {
        $parser = new MtrRawParser($this->ip);

        try {
            $this->run($parser);
        } catch (Throwable $e) {
            // A bug here (not a normal "target unreachable" mtr exit) - still leave the
            // operator's table in a terminal state instead of stuck on "running" forever.
            $this->push($parser, 'failed', substr($e->getMessage(), 0, 500));

            throw $e; // let Horizon record the real failure
        }
    }

    private function run(MtrRawParser $parser): void
    {
        // -b resolves PTR names too; -i 1 = one round per second; --raw = machine lines. The `--`
        // ends option parsing so a target that somehow begins with '-' can't be read as an mtr flag.
        $process = new Process([self::binary(), '--raw', '-b', '-i', '1', '-c', (string) $this->rounds, '--', $this->ip]);
        $process->setTimeout(max(30, $this->timeout - 10)); // headroom under the job's own kill
        $process->start();

        $buffer = '';
        $lastPush = 0.0;
        $stopped = false;

        while ($process->isRunning()) {
            $buffer = $this->consume($buffer.$process->getIncrementalOutput(), $parser);

            // Read process output frequently (smooth streaming) but only touch Redis for the
            // stop flag + snapshot on the once-a-second push cadence, not every 250ms poll.
            $now = microtime(true);
            if ($now - $lastPush >= self::PUSH_INTERVAL_SECONDS) {
                if (Cache::get("trace:{$this->runId}:stop")) {
                    $process->stop(3); // SIGTERM, then SIGKILL after 3s if it won't die
                    $stopped = true;
                    break;
                }
                $this->push($parser, 'running');
                $lastPush = $now;
            }

            usleep(self::POLL_INTERVAL_MICROS);
        }

        // Drain whatever mtr wrote between the last read and it exiting/being killed. Any
        // trailing fragment with no newline yet is still fed - mtr terminates every line,
        // but this is cheap insurance against the process being killed mid-write.
        $buffer = $this->consume($buffer.$process->getIncrementalOutput(), $parser);
        if ($buffer !== '') {
            $parser->feed($buffer);
        }

        Cache::forget("trace:{$this->runId}:stop");

        if ($stopped) {
            $this->push($parser, 'stopped');

            return;
        }

        if ($process->isSuccessful()) {
            $this->push($parser, 'done');

            return;
        }

        $stderr = trim($process->getErrorOutput());
        $this->push($parser, 'failed', $stderr !== '' ? substr($stderr, -500) : "mtr exited with code {$process->getExitCode()}");
    }

    /** Same resolution order as FpingRunner: explicit config, then common locations, then PATH. */
    private static function binary(): string
    {
        $configured = config('mymate.trace.mtr');
        if (is_string($configured) && $configured !== '' && is_executable($configured)) {
            return $configured;
        }
        foreach (['/usr/bin/mtr', '/usr/sbin/mtr', '/usr/local/bin/mtr', '/usr/local/sbin/mtr'] as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        return 'mtr'; // last resort - relies on PATH
    }

    /** Feeds every complete line to the parser, returning the unfinished remainder. */
    private function consume(string $buffer, MtrRawParser $parser): string
    {
        while (($pos = strpos($buffer, "\n")) !== false) {
            $parser->feed(substr($buffer, 0, $pos));
            $buffer = substr($buffer, $pos + 1);
        }

        return $buffer;
    }

    private function push(MtrRawParser $parser, string $status, ?string $error = null): void
    {
        $snapshot = $parser->snapshot();

        Cache::put("trace:{$this->runId}", [
            'run_id' => $this->runId,
            'device_id' => $this->deviceId,
            'status' => $status,
            'target' => $this->ip,
            'rounds_total' => $this->rounds,
            'rounds_done' => $snapshot['rounds_done'],
            'error' => $error,
            'hops' => $snapshot['hops'],
        ], now()->addMinutes(self::CACHE_TTL_MINUTES));
    }

    public function failed(Throwable $e): void
    {
        EngineLog::error('trace: job failed', [
            'device_id' => $this->deviceId,
            'run_id' => $this->runId,
            'exception' => $e::class,
            'error' => $e->getMessage(),
        ]);
    }
}
