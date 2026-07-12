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
}
