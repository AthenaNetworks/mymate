<?php

namespace App\Actions\Discovery;

use App\Enums\DiscoveryStatus;
use App\Models\DiscoveryCandidate;

/**
 * Undo an Ignore: put an ignored discovery candidate back in the review queue as New, so it can
 * be approved after all. Only an ignored candidate can be restored - an approved one is already a
 * device, and a new one has nothing to undo.
 */
class RestoreCandidate
{
    public function __invoke(DiscoveryCandidate $candidate): DiscoveryCandidate
    {
        $candidate->update(['status' => DiscoveryStatus::New]);

        return $candidate;
    }
}
