<?php

namespace App\Services\Ping;

interface Pinger
{
    /**
     * Return the subset of the given IPs that are reachable.
     *
     * @param  list<string>  $ips
     * @return list<string>
     */
    public function reachable(array $ips): array;

    /**
     * Measure every given IP: reachability plus latency/loss/jitter per host.
     *
     * @param  list<string>  $ips
     * @return array<string, PingSample> keyed by IP (only hosts that appeared in the output)
     */
    public function measure(array $ips): array;
}
