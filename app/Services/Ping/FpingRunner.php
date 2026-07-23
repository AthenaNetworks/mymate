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
        private ?string $source = null, // optional source address (fping -S) to ping FROM
        // fping -i: ms between successive targets. fping's default (~10ms) paces sends so
        // hard the total grows to minutes over a big fleet; a small value (e.g. 1ms) sweeps
        // tens of thousands quickly - and measurably more accurately, since fewer replies
        // arrive after the timeout window. null = leave fping's default.
        private ?int $intervalMs = null,
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

    /**
     * Absolute path to the fping binary. Symfony Process resolves a bare command name against
     * the caller's PATH, but php-fpm (web requests, e.g. a synchronous "scan now") frequently
     * runs with a PATH that omits /usr/local/sbin - where the required fping >=5.5 is installed
     * from source - so it wouldn't find fping at all and every sweep would come back empty.
     * Honour the configured path, else probe the usual locations, else fall back to the PATH.
     */
    private static function binary(): string
    {
        $configured = config('mymate.ping.fping');
        if (is_string($configured) && $configured !== '' && is_executable($configured)) {
            return $configured;
        }
        foreach (['/usr/local/sbin/fping', '/usr/sbin/fping', '/usr/local/bin/fping', '/usr/bin/fping'] as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        return 'fping'; // last resort - relies on PATH
    }

    /**
     * The fping argument vector for a sweep. Extracted so it can be asserted without spawning
     * fping. --json emits JSON Lines (NDJSON); it requires a count/loop mode, so -c N sends N
     * pings per host. -p spaces multi-probe sweeps out so they stay snappy.
     *
     * @return list<string>
     */
    private function commandArgs(): array
    {
        $args = [self::binary(), '--json', '-c', (string) max(1, $this->count)];
        if ($this->count > 1) {
            $args[] = '-p';
            $args[] = (string) max(1, $this->periodMs);
        }
        $args = array_merge($args, ['-r', (string) $this->retries, '-t', (string) $this->timeoutMs]);
        // -i paces successive targets; a small value is what makes a large-fleet sweep finish.
        if ($this->intervalMs !== null) {
            $args[] = '-i';
            $args[] = (string) max(0, $this->intervalMs);
        }
        // -S sets the source address to ping FROM (e.g. a WAN/VRF interface, to test that a
        // customer path can reach the target). Only added when configured.
        if ($this->source !== null && $this->source !== '') {
            $args[] = '-S';
            $args[] = $this->source;
        }

        return array_merge($args, ['-f', '-']);
    }

    /** Run one fping sweep over the given IPs and return its raw JSON-Lines stdout. */
    private function run(array $ips): string
    {
        $process = new Process($this->commandArgs(), input: implode("\n", $ips)."\n");
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
