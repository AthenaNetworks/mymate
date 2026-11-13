<?php

namespace App\Actions\Discovery;

use App\Enums\DiscoveryStatus;
use App\Models\DiscoveryCandidate;

/**
 * Dismiss a discovery candidate. An ignored candidate is never re-offered: a
 * re-scan bumps its last_seen but leaves the status untouched (see ScanSubnet). An admin can
 * undo it with RestoreCandidate.
 */
class IgnoreCandidate
{
    public function __invoke(DiscoveryCandidate $candidate): DiscoveryCandidate
    {
        $candidate->update(['status' => DiscoveryStatus::Ignored]);

        return $candidate;
    }
}
