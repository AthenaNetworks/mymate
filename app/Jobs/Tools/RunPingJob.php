<?php

namespace App\Jobs\Tools;

use App\Services\Tools\Binaries;
use App\Services\Tools\ToolRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Streams a live `ping` to one target into the tool-run cache. One reply lands per second,
 * so parsing incrementally and pushing on each reply gives the browser a naturally paced
 * live feed of RTTs with running loss/min/avg/max/jitter - the same shape mtr's per-hop
 * stats have, for the single-hop case. Cancels within a poll cycle when the run is stopped.
 */
class RunPingJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 280;

    public int $tries = 1;

    private const POLL_INTERVAL_MICROS = 200_000;

    // Rolling per-probe log kept for the console; older probes past this are dropped so a long
    // run's snapshot doesn't grow without bound in Redis.
    private const MAX_PROBES = 300;

    /** @var list<array{seq: int, ms: ?float}> */
    private array $probes = [];

    private int $sent = 0;

    private int $recv = 0;

    private ?float $last = null;

    private ?float $best = null;

    private ?float $worst = null;

    private float $mean = 0.0;

    private float $m2 = 0.0;

    public function __construct(
        public string $runId,
        public string $target,
        public int $count,
    ) {
        $this->onQueue('trace'); // shares the isolated interactive queue with live traces
    }

    public function handle(): void
    {
        try {
            $this->run();
        } catch (Throwable $e) {
            ToolRun::put($this->runId, 'ping', $this->target, 'failed', $this->result(), substr($e->getMessage(), 0, 300));

            throw $e;
        }
    }

    private function run(): void
    {
        // -n numeric (no rDNS stalls), -O reports each missed reply as it happens (so loss is
        // live, not only summarised at the end), -i 1 one probe/sec, -c bounds the run.
        $process = new Process([Binaries::ping(), '-n', '-O', '-i', '1', '-c', (string) $this->count, $this->target]);
        $process->setTimeout(max(30, $this->timeout - 10));
        $process->start();

        $buffer = '';
        $stopped = false;
        $lastStopCheck = 0.0;

        while ($process->isRunning()) {
            $buffer = $this->consume($buffer.$process->getIncrementalOutput());

            $now = microtime(true);
            if ($now - $lastStopCheck >= 1.0) {
                if (ToolRun::stopRequested($this->runId)) {
                    $process->stop(2);
                    $stopped = true;
                    break;
                }
                $lastStopCheck = $now;
            }

            usleep(self::POLL_INTERVAL_MICROS);
        }

        $this->consume($buffer.$process->getIncrementalOutput());

        ToolRun::clearStop($this->runId);

        if ($stopped) {
            ToolRun::put($this->runId, 'ping', $this->target, 'stopped', $this->result());

            return;
        }

        // ping exits non-zero when 100% of probes were lost - that's a real result (host down),
        // not a job failure, so as long as we saw probes we report it as done.
        $status = ($process->isSuccessful() || $this->sent > 0) ? 'done' : 'failed';
        $error = $status === 'failed' ? trim($process->getErrorOutput()) : null;
        ToolRun::put($this->runId, 'ping', $this->target, $status, $this->result(), $error !== '' ? $error : null);
    }

    /** Feed complete lines, keep the remainder, and push a fresh snapshot when a line moved the stats. */
    private function consume(string $buffer): string
    {
        $changed = false;
        while (($pos = strpos($buffer, "\n")) !== false) {
            $changed = $this->line(substr($buffer, 0, $pos)) || $changed;
            $buffer = substr($buffer, $pos + 1);
        }

        if ($changed) {
            ToolRun::put($this->runId, 'ping', $this->target, 'running', $this->result());
        }

        return $buffer;
    }

    /** @return bool whether the line was a probe result (reply or miss) that changed the stats */
    private function line(string $line): bool
    {
        // A reply: "64 bytes from 1.1.1.1: icmp_seq=1 ttl=57 time=12.3 ms"
        if (preg_match('/icmp_seq=(\d+).*time=([\d.]+)\s*ms/', $line, $m)) {
            $this->record((int) $m[1], (float) $m[2]);

            return true;
        }

        // A miss (ping -O): "no answer yet for icmp_seq=1"
        if (preg_match('/no answer yet for icmp_seq=(\d+)/', $line, $m)) {
            $this->record((int) $m[1], null);

            return true;
        }

        return false;
    }

    private function record(int $seq, ?float $ms): void
    {
        $this->sent++;
        $this->probes[] = ['seq' => $seq, 'ms' => $ms];
        if (count($this->probes) > self::MAX_PROBES) {
            array_shift($this->probes);
        }

        if ($ms === null) {
            return;
        }

        $this->recv++;
        $this->last = $ms;
        $this->best = $this->best === null ? $ms : min($this->best, $ms);
        $this->worst = $this->worst === null ? $ms : max($this->worst, $ms);

        // Welford online mean/variance, same as the trace parser.
        $delta = $ms - $this->mean;
        $this->mean += $delta / $this->recv;
        $this->m2 += $delta * ($ms - $this->mean);
    }

    /** @return array<string, mixed> */
    private function result(): array
    {
        $answered = $this->recv > 0;

        return [
            'sent' => $this->sent,
            'recv' => $this->recv,
            'loss_pct' => $this->sent > 0 ? round((1 - $this->recv / $this->sent) * 100, 1) : 0.0,
            'last_ms' => $answered ? round($this->last, 2) : null,
            'avg_ms' => $answered ? round($this->mean, 2) : null,
            'best_ms' => $answered ? round($this->best, 2) : null,
            'worst_ms' => $answered ? round($this->worst, 2) : null,
            'stdev_ms' => $answered ? round($this->recv > 1 ? sqrt(max(0.0, $this->m2) / $this->recv) : 0.0, 2) : null,
            'probes' => $this->probes,
        ];
    }
}
