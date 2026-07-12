<?php

namespace App\Jobs;

use App\Actions\Discovery\ScanSubnet;
use App\Models\Subnet;
use App\Support\EngineLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Thin queue envelope: scan one subnet on the isolated `scan` queue so a
 * slow sweep never delays the ping/poll loops. `tries=1` + a per-subnet overlap lock
 * mean a still-running scan skips its next tick instead of piling up (and two
 * dispatchers can't double-scan a subnet).
 */
class ScanSubnetJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $subnetId)
    {
        $this->onQueue('scan');
    }

    public function handle(ScanSubnet $scan): void
    {
        $subnet = Subnet::find($this->subnetId);
        // Agent-assigned subnets are scanned by their remote agent (inside the management
        // network), never centrally - guard here so no dispatch path can leak one to a
        // central sweep from the wrong network.
        if ($subnet === null || ! $subnet->enabled || $subnet->agent_id !== null) {
            return;
        }

        $scan($subnet);
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        // Sweeps can be slow, so a generous lock window before it auto-expires.
        return [(new WithoutOverlapping("scan-subnet-{$this->subnetId}"))->dontRelease()->expireAfter(300)];
    }

    public function failed(Throwable $e): void
    {
        EngineLog::error('discovery: scan job failed', [
            'subnet_id' => $this->subnetId,
            'exception' => $e::class,
            'error' => $e->getMessage(),
        ]);
    }
}
