<?php

namespace App\Enums;

/**
 * Lifecycle of a discovered host in the review queue.
 * Discovery never auto-adds devices - a candidate stays `New` until an operator
 * approves it (-> becomes a device) or ignores it (dismissed; never resurrected).
 */
enum DiscoveryStatus: string
{
    case New = 'new';
    case Approved = 'approved';
    case Ignored = 'ignored';
}
