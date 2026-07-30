<?php

namespace App\Services\Trace;

/**
 * Turns `mtr --raw -b` stdout lines into the per-hop stats the trace API exposes
 * (sent/recv/loss + last/avg/best/worst/stdev latency in ms). Feed it lines as they
 * arrive from the running process - it holds all state so RunTraceJob can pull a
 * fresh snapshot() every ~second without re-parsing anything already seen.
 *
 * Raw grammar (mtr-tiny 0.95, `mtr --raw -b -i 1 -c <rounds> <ip>`):
 *   x <ttl> <seq>          probe sent for hop <ttl>
 *   h <ttl> <ip>           responder address for hop <ttl> (repeats - idempotent to set)
 *   p <ttl> <usec> <seq>   round-trip time in MICROSECONDS for hop <ttl>
 *   d <ttl> <hostname>     lazy reverse-DNS name for hop <ttl> (may never arrive)
 * ttl 0 is the source (mtr itself), never part of the reported path - ignored.
 */
class MtrRawParser
{
    /**
     * Per-ttl running stats. mean/m2 are Welford's online-variance accumulators
     * (avoids keeping every sample just to compute avg/stdev).
     *
     * @var array<int, array{ip: ?string, ptr: ?string, sent: int, recv: int, last: ?float, best: ?float, worst: ?float, mean: float, m2: float}>
     */
    private array $hops = [];

    /** Count of `x 1 ...` lines - the number of rounds mtr has started firing probes for. */
    private int $roundsDone = 0;

    /** Highest ttl seen in any `x`/`h`/`p`/`d` line so far. */
    private int $maxTtl = 0;

    public function __construct(private readonly string $target)
    {
    }

    public function feed(string $line): void
    {
        $line = trim($line);
        if ($line === '') {
            return;
        }

        $parts = preg_split('/\s+/', $line);
        if ($parts === false || count($parts) < 2) {
            return; // not a line shape we understand - ignore rather than blow up mid-stream
        }

        $ttl = (int) $parts[1];
        if ($ttl < 1) {
            return; // ttl 0 = source hop, or an unparsable ttl - not part of the reported path
        }

        $this->maxTtl = max($this->maxTtl, $ttl);

        match ($parts[0]) {
            'x' => $this->onSent($ttl),
            'h' => $this->onAddress($ttl, $parts[2] ?? null),
            'p' => $this->onRtt($ttl, $parts[2] ?? null),
            'd' => $this->onPtr($ttl, $parts[2] ?? null),
            default => null, // unhandled raw line type (future mtr additions, event lines, ...)
        };
    }

    /**
     * Current view of the run: hop stats plus rounds_done, matching the API contract's
     * `hops` array. Collapses the trailing-target artifact (see targetCutoff()) so a
     * caller never has to know about mtr's path-length hunting.
     *
     * @return array{rounds_done: int, hops: list<array<string, mixed>>}
     */
    public function snapshot(): array
    {
        $cutoff = $this->targetCutoff();

        $hops = [];
        for ($ttl = 1; $ttl <= $cutoff; $ttl++) {
            $hops[] = $this->hopSnapshot($ttl, $this->hops[$ttl] ?? null);
        }

        return ['rounds_done' => $this->roundsDone, 'hops' => $hops];
    }

    private function onSent(int $ttl): void
    {
        $hop = &$this->hop($ttl);
        $hop['sent']++;

        if ($ttl === 1) {
            $this->roundsDone++;
        }
    }

    private function onAddress(int $ttl, ?string $ip): void
    {
        if ($ip === null) {
            return;
        }

        $hop = &$this->hop($ttl);
        $hop['ip'] = $ip; // repeated 'h' lines for the same hop just re-confirm it - idempotent
    }

    private function onPtr(int $ttl, ?string $hostname): void
    {
        if ($hostname === null) {
            return;
        }

        $hop = &$this->hop($ttl);
        $hop['ptr'] = $hostname;
    }

    private function onRtt(int $ttl, ?string $usec): void
    {
        if ($usec === null || ! is_numeric($usec)) {
            return;
        }

        $ms = ((float) $usec) / 1000.0;

        $hop = &$this->hop($ttl);
        $hop['recv']++;
        $hop['last'] = $ms;
        $hop['best'] = $hop['best'] === null ? $ms : min($hop['best'], $ms);
        $hop['worst'] = $hop['worst'] === null ? $ms : max($hop['worst'], $ms);

        // Welford's online algorithm - avg/variance in one pass, no stored sample list.
        $delta = $ms - $hop['mean'];
        $hop['mean'] += $delta / $hop['recv'];
        $hop['m2'] += $delta * ($ms - $hop['mean']);
    }

    /** @return array{ip: ?string, ptr: ?string, sent: int, recv: int, last: ?float, best: ?float, worst: ?float, mean: float, m2: float} */
    private function &hop(int $ttl): array
    {
        if (! isset($this->hops[$ttl])) {
            $this->hops[$ttl] = [
                'ip' => null, 'ptr' => null, 'sent' => 0, 'recv' => 0,
                'last' => null, 'best' => null, 'worst' => null, 'mean' => 0.0, 'm2' => 0.0,
            ];
        }

        return $this->hops[$ttl];
    }

    /**
     * mtr grows the probed ttl range while it hunts for the path length, so the target's
     * own IP can show up at more than one trailing ttl (e.g. ttl 8 *and* 9 both answering
     * as the final destination in the same run). The first ttl the target answers at IS
     * the real path length - anything past it is that hunting artifact, not a real hop.
     * Returns maxTtl unchanged when the target hasn't answered yet (or never does).
     */
    private function targetCutoff(): int
    {
        for ($ttl = 1; $ttl <= $this->maxTtl; $ttl++) {
            if (($this->hops[$ttl]['ip'] ?? null) === $this->target) {
                return $ttl;
            }
        }

        return $this->maxTtl;
    }

    /**
     * @param ?array{ip: ?string, ptr: ?string, sent: int, recv: int, last: ?float, best: ?float, worst: ?float, mean: float, m2: float} $hop
     * @return array<string, mixed>
     */
    private function hopSnapshot(int $ttl, ?array $hop): array
    {
        $sent = $hop['sent'] ?? 0;
        $recv = $hop['recv'] ?? 0;
        $answered = $recv > 0; // a hop that never answered reports null ip/ptr/latency, 100% loss

        return [
            'ttl' => $ttl,
            'ip' => $answered ? $hop['ip'] : null,
            'ptr' => $answered ? $hop['ptr'] : null,
            'sent' => $sent,
            'recv' => $recv,
            'loss_pct' => $sent > 0 ? round((1 - $recv / $sent) * 100, 1) : 100.0,
            'last_ms' => $answered ? round($hop['last'], 1) : null,
            'avg_ms' => $answered ? round($hop['mean'], 1) : null,
            'best_ms' => $answered ? round($hop['best'], 1) : null,
            'worst_ms' => $answered ? round($hop['worst'], 1) : null,
            // Population stdev over the received samples (matches mtr's own STDEV column).
            'stdev_ms' => $answered ? round($recv > 1 ? sqrt($hop['m2'] / $recv) : 0.0, 1) : null,
        ];
    }
}
