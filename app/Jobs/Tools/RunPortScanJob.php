<?php

namespace App\Jobs\Tools;

use App\Services\Tools\PortScanner;
use App\Services\Tools\ToolRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * A TCP connect scan of one target over a fixed port list, streamed into the tool-run cache
 * so results fill in live as each port resolves. Pure PHP (PortScanner) - no nmap, no root.
 */
class RunPortScanJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 280;

    public int $tries = 1;

    /** @var list<array{port: int, state: string, service: ?string}> */
    private array $results = [];

    private int $done = 0;

    /**
     * @param  list<int>  $ports
     */
    public function __construct(
        public string $runId,
        public string $target,
        public array $ports,
    ) {
        $this->onQueue('trace');
    }

    public function handle(): void
    {
        $scanner = new PortScanner(
            (int) config('mymate.tools.connect_timeout_ms', 700),
            (int) config('mymate.tools.connect_concurrency', 64),
        );

        try {
            $scanner->scan(
                $this->target,
                $this->ports,
                function (int $port, string $state, ?string $service): void {
                    $this->done++;
                    // Only open ports are worth keeping in the streamed result; closed/filtered
                    // are counted for progress but would just be noise in a listening-ports table.
                    if ($state === 'open') {
                        $this->results[] = ['port' => $port, 'state' => $state, 'service' => $service];
                        usort($this->results, fn ($a, $b) => $a['port'] <=> $b['port']);
                    }
                    $this->push('running');
                },
                fn (): bool => ToolRun::stopRequested($this->runId),
            );
        } catch (Throwable $e) {
            $this->push('failed', substr($e->getMessage(), 0, 300));

            throw $e;
        }

        $stopped = ToolRun::stopRequested($this->runId);
        ToolRun::clearStop($this->runId);
        $this->push($stopped ? 'stopped' : 'done');
    }

    private function push(string $status, ?string $error = null): void
    {
        ToolRun::put($this->runId, 'portscan', $this->target, $status, [
            'total' => count($this->ports),
            'scanned' => $this->done,
            'open' => $this->results,
        ], $error);
    }
}
