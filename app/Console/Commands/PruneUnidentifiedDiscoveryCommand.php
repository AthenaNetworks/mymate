<?php

namespace App\Console\Commands;

use App\Enums\DiscoveryStatus;
use App\Enums\PollMethod;
use App\Models\Device;
use App\Models\DiscoveryCandidate;
use Illuminate\Console\Command;

/**
 * Remove discovery cruft created before the  "identified-only candidates" fix:
 *  - unidentified, un-reviewed candidates (responded to ICMP but matched no credential), and
 *  - (with --devices) the orphan null-credential SNMP devices that the old PromoteCandidate
 *    SNMP-fallback created - only ones with no interfaces (they never polled anything).
 *
 * Safe + idempotent: never touches approved candidates or devices that actually have interfaces.
 */
class PruneUnidentifiedDiscoveryCommand extends Command
{
    protected $signature = 'mymate:discovery:prune {--devices : also delete orphan null-credential SNMP devices that have no interfaces}';

    protected $description = 'Prune unidentified discovery candidates (and optionally the broken null-credential SNMP devices they created)';

    public function handle(): int
    {
        $candidates = DiscoveryCandidate::whereNull('detected_method')
            ->where('status', '!=', DiscoveryStatus::Approved->value)
            ->get();

        foreach ($candidates as $candidate) {
            $candidate->delete();
        }
        $this->info("Pruned {$candidates->count()} unidentified discovery candidate(s).");

        if ($this->option('devices')) {
            $orphans = Device::query()
                ->where('poll_method', PollMethod::Snmp)
                ->whereNull('credential_id')
                ->whereDoesntHave('interfaces')
                ->get();

            foreach ($orphans as $device) {
                $this->line("  removing orphan device {$device->name} ({$device->mgmt_ip})");
                $device->delete();
            }
            $this->info("Removed {$orphans->count()} orphan null-credential SNMP device(s).");
        }

        return self::SUCCESS;
    }
}
