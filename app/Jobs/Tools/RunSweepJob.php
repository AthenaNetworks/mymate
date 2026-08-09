<?php

namespace App\Jobs\Tools;

use App\Services\Tools\Binaries;
use App\Services\Tools\NetbiosLookup;
use App\Services\Tools\PortScanner;
use App\Services\Tools\Targets;
use App\Services\Tools\ToolRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Sweeps an IPv4 subnet for live hosts and enriches each with whatever cheap identity we
 * can pull without an agent: reverse DNS, a NetBIOS node-status name/MAC (UDP 137), and
 * optionally a small TCP connect scan. It streams in two visible phases - hosts appear as
 * fping finds them (phase 1), then their details fill in one host at a time (phase 2) - so
 * the operator watches it populate rather than staring at a spinner. Cancels between hosts.
 */
class RunSweepJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 280;

    public int $tries = 1;

    private const POLL_INTERVAL_MICROS = 200_000;

    /** @var array<string, array<string, mixed>>  ip => host row (insertion order preserved) */
    private array $hosts = [];

    private int $total = 0;

    private string $phase = 'discovering';

    /**
     * @param  list<int>  $ports  ports to connect-scan per live host; empty = skip port scanning
     */
    public function __construct(
        public string $runId,
        public string $cidr,
        public array $ports,
    ) {
        $this->onQueue('trace');
    }

    public function handle(): void
    {
        $this->total = Targets::hostCount($this->cidr);

        try {
            $stopped = $this->discover();
            if (! $stopped) {
                $stopped = $this->enrich();
            }
        } catch (Throwable $e) {
            $this->push('failed', substr($e->getMessage(), 0, 300));

            throw $e;
        }

        ToolRun::clearStop($this->runId);
        $this->push($stopped ? 'stopped' : 'done');
    }

    /** Phase 1: fping the range, adding each alive host (details still null) as it answers. */
    private function discover(): bool
    {
        // -a prints only alive addresses (one per line), -q hushes the per-host stderr summary,
        // -r 1 keeps it quick, -g generates the range from the CIDR.
        $process = new Process([Binaries::fping(), '-a', '-q', '-r', '1', '-g', $this->cidr]);
        $process->setTimeout(max(30, $this->timeout - 30)); // leave headroom for enrichment
        $process->start();

        $buffer = '';
        $lastStopCheck = 0.0;

        while ($process->isRunning()) {
            $buffer = $this->consumeAlive($buffer.$process->getIncrementalOutput());

            $now = microtime(true);
            if ($now - $lastStopCheck >= 1.0) {
                if (ToolRun::stopRequested($this->runId)) {
                    $process->stop(2);

                    return true;
                }
                $lastStopCheck = $now;
            }

            usleep(self::POLL_INTERVAL_MICROS);
        }

        $this->consumeAlive($buffer.$process->getIncrementalOutput());

        // fping exits 1 when nothing was reachable - that's a normal empty sweep, not a failure.
        // Only a genuine error (bad args / permission) gives >=2, and we surface that.
        if (! $process->isSuccessful() && $process->getExitCode() >= 2 && $this->hosts === []) {
            $stderr = trim($process->getErrorOutput());
            throw new \RuntimeException($stderr !== '' ? $stderr : 'fping failed to sweep the range');
        }

        return false;
    }

    /** Phase 2: fill in reverse DNS, NetBIOS and (optionally) open ports for each live host. */
    private function enrich(): bool
    {
        $this->phase = 'resolving';
        $this->push('running');

        $netbiosOn = (bool) config('mymate.tools.netbios', true);
        $netbiosTimeout = (int) config('mymate.tools.netbios_timeout_ms', 700);
        $scanner = $this->ports === [] ? null : new PortScanner(
            (int) config('mymate.tools.connect_timeout_ms', 700),
            (int) config('mymate.tools.connect_concurrency', 64),
        );

        foreach (array_keys($this->hosts) as $ip) {
            if (ToolRun::stopRequested($this->runId)) {
                return true;
            }

            $rdns = gethostbyaddr($ip);
            $this->hosts[$ip]['rdns'] = ($rdns !== false && $rdns !== $ip) ? $rdns : null;

            if ($netbiosOn) {
                $nb = NetbiosLookup::query($ip, $netbiosTimeout);
                $this->hosts[$ip]['netbios'] = $nb['name'] ?? null;
                $this->hosts[$ip]['group'] = $nb['group'] ?? null;
                $this->hosts[$ip]['mac'] = $nb['mac'] ?? null;
            }

            if ($scanner !== null) {
                $open = [];
                $scanner->scan(
                    $ip,
                    $this->ports,
                    function (int $port, string $state, ?string $service) use (&$open): void {
                        if ($state === 'open') {
                            $open[] = ['port' => $port, 'service' => $service];
                        }
                    },
                    fn (): bool => ToolRun::stopRequested($this->runId),
                );
                usort($open, fn ($a, $b) => $a['port'] <=> $b['port']);
                $this->hosts[$ip]['ports'] = $open;
            }

            $this->hosts[$ip]['pending'] = false;
            $this->push('running');
        }

        return false;
    }

    private function consumeAlive(string $buffer): string
    {
        $changed = false;
        while (($pos = strpos($buffer, "\n")) !== false) {
            $ip = trim(substr($buffer, 0, $pos));
            $buffer = substr($buffer, $pos + 1);

            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && ! isset($this->hosts[$ip])) {
                $this->hosts[$ip] = [
                    'ip' => $ip, 'rdns' => null, 'netbios' => null, 'group' => null,
                    'mac' => null, 'ports' => [], 'pending' => true,
                ];
                $changed = true;
            }
        }

        if ($changed) {
            $this->push('running');
        }

        return $buffer;
    }

    private function push(string $status, ?string $error = null): void
    {
        // Sort by numeric IP so the table is stable as hosts stream in out of order.
        $hosts = array_values($this->hosts);
        usort($hosts, fn ($a, $b) => ip2long($a['ip']) <=> ip2long($b['ip']));

        ToolRun::put($this->runId, 'sweep', $this->cidr, $status, [
            'total' => $this->total,
            'phase' => $status === 'running' ? $this->phase : $status,
            'alive' => count($this->hosts),
            'hosts' => $hosts,
        ], $error);
    }
}
