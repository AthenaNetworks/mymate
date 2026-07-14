<?php

namespace App\Services\Ping;

use Symfony\Component\Process\Process;

/**
 * Batch ICMP via the `fping` binary. One invocation pings the whole fleet in
 * parallel. Hosts are passed on stdin (`-f -`) to avoid ARG_MAX on large fleets.
 *
 * Note: fping needs raw-socket privileges (setuid root or cap_net_raw) for the
 * worker user in production.
 */
class FpingRunner implements Pinger
{
    public function __construct(
        private int $timeoutMs = 500,
        private int $retries = 1,
        private int $processTimeout = 30,
        private int $count = 1,
        private int $periodMs = 300,
    ) {}

    public function reachable(array $ips): array
    {
        if ($ips === []) {
            return [];
        }

        return self::parseReachable($this->run($ips), $ips);
    }

    public function measure(array $ips): array
    {
        if ($ips === []) {
            return [];
        }

        return self::parseSamples($this->run($ips), $ips);
    }

    /** Run one fping sweep over the given IPs and return its raw JSON-Lines stdout. */
    private function run(array $ips): string
    {
        // --json emits JSON Lines (NDJSON); it requires a count/loop mode, so -c N sends N
        // pings per host. -p spaces multi-probe sweeps out so they stay snappy. Each host
        // gets a {"summary": {...}} line (rcv/loss) and one {"resp": {...}} line per reply.
        $args = ['fping', '--json', '-c', (string) max(1, $this->count)];
        if ($this->count > 1) {
            $args[] = '-p';
            $args[] = (string) max(1, $this->periodMs);
        }
        $args = array_merge($args, ['-r', (string) $this->retries, '-t', (string) $this->timeoutMs, '-f', '-']);

        $process = new Process($args, input: implode("\n", $ips)."\n");
        $process->setTimeout($this->processTimeout);
        $process->run();

        // fping exits non-zero when ANY host is unreachable - that is normal here.
        return $process->getOutput();
    }

    /**
     * Pure parse of fping's JSON-Lines stdout, intersected with the queried IPs.
     *
     * A host is reachable when its "summary" line reports at least one reply
     * (`rcv >= 1`). Non-summary lines and any malformed lines are ignored.
     *
     * @param  list<string>  $ips
     * @return list<string>
     */
    public static function parseReachable(string $stdout, array $ips): array
    {
        $samples = self::parseSamples($stdout, $ips);

        return array_values(array_filter($ips, static fn (string $ip): bool => ($samples[$ip] ?? null)?->reachable ?? false));
    }

    /**
     * Pure parse of fping's JSON-Lines stdout into per-host {@see PingSample}s.
     *
     * Latency is derived from the `resp` lines (each carries an `rtt` in ms) rather than the
     * summary, which keeps it robust across fping versions: rtt = mean of the replies, jitter
     * = max-min of the replies. Loss and reachability come from the `summary` line.
     *
     * @param  list<string>  $ips
     * @return array<string, PingSample>
     */
    public static function parseSamples(string $stdout, array $ips): array
    {
        /** @var array<string, list<float>> $rtts */
        $rtts = [];
        /** @var array<string, array{rcv:int, loss:?float}> $summary */
        $summary = [];

        foreach (explode("\n", $stdout) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (! is_array($decoded)) {
                continue;
            }

            if (isset($decoded['resp']['host'], $decoded['resp']['rtt']) && is_numeric($decoded['resp']['rtt'])) {
                $rtts[(string) $decoded['resp']['host']][] = (float) $decoded['resp']['rtt'];
            }

            $s = $decoded['summary'] ?? null;
            if (is_array($s) && isset($s['host'])) {
                $summary[(string) $s['host']] = [
                    'rcv' => (int) ($s['rcv'] ?? 0),
                    'loss' => isset($s['loss']) && is_numeric($s['loss']) ? (float) $s['loss'] : null,
                ];
            }
        }

        $out = [];
        foreach ($ips as $ip) {
            if (! isset($summary[$ip])) {
                continue; // host never appeared in the output
            }
            $rcv = $summary[$ip]['rcv'];
            $hostRtts = $rtts[$ip] ?? [];
            $rttMs = $hostRtts === [] ? null : round(array_sum($hostRtts) / count($hostRtts), 3);
            $jitter = count($hostRtts) >= 2 ? round(max($hostRtts) - min($hostRtts), 3) : null;
            $loss = $summary[$ip]['loss'] ?? ($rcv >= 1 ? 0.0 : 100.0);

            $out[$ip] = new PingSample(
                reachable: $rcv >= 1,
                rttMs: $rttMs,
                lossPct: $loss,
                jitterMs: $jitter,
            );
        }

        return $out;
    }
}
