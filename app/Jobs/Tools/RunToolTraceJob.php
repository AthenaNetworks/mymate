<?php

namespace App\Jobs\Tools;

use App\Services\Tools\Binaries;
use App\Services\Tools\ToolRun;
use App\Services\Trace\MtrRawParser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The Tools-page trace: an `mtr --raw` to an arbitrary operator-typed target, streamed into
 * the tool-run cache. It reuses MtrRawParser (the exact same hop accounting the device
 * inspector's trace uses) so the two traces can never disagree about how a path is read;
 * only the target source and the cache envelope differ.
 */
class RunToolTraceJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 280;

    public int $tries = 1;

    private const PUSH_INTERVAL_SECONDS = 1.0;

    private const POLL_INTERVAL_MICROS = 250_000;

    public function __construct(
        public string $runId,
        public string $target,
        public int $rounds,
    ) {
        $this->onQueue('trace');
    }

    public function handle(): void
    {
        $parser = new MtrRawParser($this->target);

        try {
            $this->run($parser);
        } catch (Throwable $e) {
            $this->push($parser, 'failed', substr($e->getMessage(), 0, 300));

            throw $e;
        }
    }

    private function run(MtrRawParser $parser): void
    {
        // --raw = machine lines, -b = also resolve PTR, -i 1 = one round/sec, `--` ends option
        // parsing so a target starting with '-' can't be read as a flag.
        $process = new Process([Binaries::mtr(), '--raw', '-b', '-i', '1', '-c', (string) $this->rounds, '--', $this->target]);
        $process->setTimeout(max(30, $this->timeout - 10));
        $process->start();

        $buffer = '';
        $lastPush = 0.0;
        $stopped = false;

        while ($process->isRunning()) {
            $buffer = $this->consume($buffer.$process->getIncrementalOutput(), $parser);

            $now = microtime(true);
            if ($now - $lastPush >= self::PUSH_INTERVAL_SECONDS) {
                if (ToolRun::stopRequested($this->runId)) {
                    $process->stop(3);
                    $stopped = true;
                    break;
                }
                $this->push($parser, 'running');
                $lastPush = $now;
            }

            usleep(self::POLL_INTERVAL_MICROS);
        }

        $buffer = $this->consume($buffer.$process->getIncrementalOutput(), $parser);
        if ($buffer !== '') {
            $parser->feed($buffer);
        }

        ToolRun::clearStop($this->runId);

        if ($stopped) {
            $this->push($parser, 'stopped');

            return;
        }

        if ($process->isSuccessful()) {
            $this->push($parser, 'done');

            return;
        }

        $stderr = trim($process->getErrorOutput());
        $this->push($parser, 'failed', $stderr !== '' ? substr($stderr, -300) : "mtr exited with code {$process->getExitCode()}");
    }

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

        ToolRun::put($this->runId, 'trace', $this->target, $status, [
            'rounds_total' => $this->rounds,
            'rounds_done' => $snapshot['rounds_done'],
            'hops' => $snapshot['hops'],
        ], $error);
    }
}
