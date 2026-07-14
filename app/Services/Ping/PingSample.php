<?php

namespace App\Services\Ping;

/**
 * One host's result from a ping sweep: reachability plus latency/loss/jitter. `rttMs` is
 * the average RTT of the replies (null when nothing came back); `lossPct` is 0-100;
 * `jitterMs` is the spread (max-min) across the probes, null with fewer than two replies.
 */
final class PingSample
{
    public function __construct(
        public readonly bool $reachable,
        public readonly ?float $rttMs = null,
        public readonly ?float $lossPct = null,
        public readonly ?float $jitterMs = null,
    ) {}
}
