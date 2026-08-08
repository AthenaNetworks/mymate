<?php

namespace App\Services\Trace;

/**
 * Turns `mtr --raw -b` stdout lines into the per-hop stats the trace API exposes
 * (sent/recv/loss + last/avg/best/worst/stdev latency in ms). Feed it lines as they
 * arrive from the running process - it holds all state so RunTraceJob can pull a
 * fresh snapshot() every ~second without re-parsing anything already seen.
 *
 * Raw grammar (mtr-tiny 0.95, `mtr --raw -b -i 1 -c <rounds> <ip>`):
 *   x <idx> <seq>          probe sent for hop <idx>
 *   h <idx> <ip>           responder address for hop <idx> (repeats - idempotent to set)
 *   p <idx> <usec> <seq>   round-trip time in MICROSECONDS for hop <idx>
 *   d <idx> <hostname>     lazy reverse-DNS name for hop <idx> (may never arrive)
 *
 * The <idx> field is a 0-based hop index: index 0 IS the first real hop (the local
 * gateway), not the source. We keep it and present hops 1-based to the caller so the
 * table reads like traceroute (hop 1, 2, 3, ...).
 */
class MtrRawParser
{
    /**
     * Per-hop running stats, keyed by the raw 0-based hop index. mean/m2 are Welford's
     * online-variance accumulators (avoids keeping every sample just to compute avg/stdev).
     *
     * @var array<int, array{ip: ?string, ptr: ?string, sent: int, recv: int, last: ?float, best: ?float, worst: ?float, mean: float, m2: float}>
     */
    private array $hops = [];

    /** Count of `x 0 ...` lines - the number of rounds mtr has started firing probes for. */
    private int $roundsDone = 0;

    /** Highest hop index seen in any `x`/`h`/`p`/`d` line so far, or -1 if nothing yet. */
    private int $maxIdx = -1;

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

        if (! is_numeric($parts[1])) {
            return; // no usable hop index - not a raw path line
        }

        $idx = (int) $parts[1];
        if ($idx < 0) {
            return; // shouldn't happen, but a negative index is not a real hop
        }

        $this->maxIdx = max($this->maxIdx, $idx);

        match ($parts[0]) {
            'x' => $this->onSent($idx),
            'h' => $this->onAddress($idx, $parts[2] ?? null),
            'p' => $this->onRtt($idx, $parts[2] ?? null),
            'd' => $this->onPtr($idx, $parts[2] ?? null),
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
        for ($idx = 0; $idx <= $cutoff; $idx++) {
            $hops[] = $this->hopSnapshot($idx, $this->hops[$idx] ?? null);
        }

        return ['rounds_done' => $this->roundsDone, 'hops' => $hops];
    }

    private function onSent(int $idx): void
    {
        $hop = &$this->hop($idx);
        $hop['sent']++;

        if ($idx === 0) {
            $this->roundsDone++; // first probe of each round is always the index-0 hop
        }
    }

    private function onAddress(int $idx, ?string $ip): void
    {
        if ($ip === null) {
            return;
        }

        $hop = &$this->hop($idx);
        $hop['ip'] = $ip; // repeated 'h' lines for the same hop just re-confirm it - idempotent
    }

    private function onPtr(int $idx, ?string $hostname): void
    {
        if ($hostname === null) {
            return;
        }

        $hop = &$this->hop($idx);
        $hop['ptr'] = $hostname;
    }

    private function onRtt(int $idx, ?string $usec): void
    {
        if ($usec === null || ! is_numeric($usec)) {
            return;
        }

        $ms = ((float) $usec) / 1000.0;

        $hop = &$this->hop($idx);
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
    private function &hop(int $idx): array
    {
        if (! isset($this->hops[$idx])) {
            $this->hops[$idx] = [
                'ip' => null, 'ptr' => null, 'sent' => 0, 'recv' => 0,
                'last' => null, 'best' => null, 'worst' => null, 'mean' => 0.0, 'm2' => 0.0,
            ];
        }

        return $this->hops[$idx];
    }

    /**
     * mtr grows the probed range while it hunts for the path length, so the target's own
     * IP can show up at more than one trailing index (e.g. index 7 *and* 8 both answering
     * as the final destination in the same run). The first index the target answers at IS
     * the real path length - anything past it is that hunting artifact, not a real hop.
     * Returns maxIdx unchanged when the target hasn't answered yet (or never does), and
     * -1 when nothing has been seen at all.
     */
    private function targetCutoff(): int
    {
        for ($idx = 0; $idx <= $this->maxIdx; $idx++) {
            if (($this->hops[$idx]['ip'] ?? null) === $this->target) {
                return $idx;
            }
        }

        return $this->maxIdx;
    }

    /**
     * @param ?array{ip: ?string, ptr: ?string, sent: int, recv: int, last: ?float, best: ?float, worst: ?float, mean: float, m2: float} $hop
     * @return array<string, mixed>
     */
    private function hopSnapshot(int $idx, ?array $hop): array
    {
        $sent = $hop['sent'] ?? 0;
        $recv = $hop['recv'] ?? 0;
        $answered = $recv > 0; // a hop that never answered reports null ip/ptr/latency, 100% loss

        return [
            'ttl' => $idx + 1, // present 1-based so the table numbers hops like traceroute
            'ip' => $answered ? $hop['ip'] : null,
            'ptr' => $answered ? $hop['ptr'] : null,
            'sent' => $sent,
            'recv' => $recv,
            'loss_pct' => $sent > 0 ? round((1 - $recv / $sent) * 100, 1) : 100.0,
            'last_ms' => $answered ? round($hop['last'], 1) : null,
            'avg_ms' => $answered ? round($hop['mean'], 1) : null,
            'best_ms' => $answered ? round($hop['best'], 1) : null,
            'worst_ms' => $answered ? round($hop['worst'], 1) : null,
            // Population stdev over the received samples (matches mtr's own STDEV column). max(0)
            // guards against a tiny negative m2 from Welford floating-point cancellation, which
            // would otherwise make sqrt() NaN and break JSON encoding of the snapshot.
            'stdev_ms' => $answered ? round($recv > 1 ? sqrt(max(0.0, $hop['m2']) / $recv) : 0.0, 1) : null,
        ];
    }
}
