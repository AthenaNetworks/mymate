<?php

namespace App\Services\Ping;

use RuntimeException;

/**
 * An fping sweep hit the process timeout and was killed before it finished. fping prints its
 * per-host summaries only at the end, so all that survives is the replies seen so far: `$samples`
 * holds those (every one reachable). A host missing from it wasn't measured, which is not the same
 * as down, so callers must leave it alone rather than count it as a missed sweep. (GH #61)
 */
class PingSweepTimedOut extends RuntimeException
{
    /**
     * @param  array<string, PingSample>  $samples  the hosts that replied before the kill
     */
    public function __construct(public readonly array $samples, int $hosts, int $timeoutS)
    {
        parent::__construct("fping was killed after {$timeoutS}s with {$hosts} hosts in the sweep");
    }
}
