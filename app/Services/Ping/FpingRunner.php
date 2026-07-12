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
    ) {}

    public function reachable(array $ips): array
    {
        if ($ips === []) {
            return [];
        }

        // --json emits JSON Lines (NDJSON) on stdout; it requires a count/loop mode,
        // so -c 1 sends one ping per host (still retried up to -r times). Each host
        // gets a {"summary": {...}} line carrying rcv (replies received).
        $process = new Process(
            ['fping', '--json', '-c', '1', '-r', (string) $this->retries, '-t', (string) $this->timeoutMs, '-f', '-'],
            input: implode("\n", $ips)."\n",
        );
        $process->setTimeout($this->processTimeout);
        $process->run();

        // fping exits non-zero when ANY host is unreachable - that is normal here.
        // We parse stdout regardless.
        return self::parseReachable($process->getOutput(), $ips);
    }

    /**
     * Pure parse of fping's JSON-Lines stdout, intersected with the queried IPs.
     *
     * Each line is one JSON object; a host is reachable when its "summary" line
     * reports at least one reply received (`rcv >= 1`). Non-summary lines
     * ("resp"/"timeout") and any malformed lines are ignored.
     *
     * @param  list<string>  $ips
     * @return list<string>
     */
    public static function parseReachable(string $stdout, array $ips): array
    {
        $alive = [];

        foreach (explode("\n", $stdout) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);
            $summary = is_array($decoded) ? ($decoded['summary'] ?? null) : null;

            if (is_array($summary) && isset($summary['host']) && (int) ($summary['rcv'] ?? 0) >= 1) {
                $alive[(string) $summary['host']] = true;
            }
        }

        return array_values(array_filter($ips, static fn (string $ip): bool => isset($alive[$ip])));
    }
}
