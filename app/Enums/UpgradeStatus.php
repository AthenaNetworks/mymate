<?php

namespace App\Enums;

/**
 * Lifecycle of a firmware upgrade, surfaced in the UI as a spinner ->
 * result. `null` on the device means "never upgraded / idle".
 */
enum UpgradeStatus: string
{
    case Queued = 'queued';         // accepted, waiting for a worker / its turn
    case Checking = 'checking';     // contacting the update server
    case Downloading = 'downloading';
    case Rebooting = 'rebooting';   // reboot issued, waiting for it to come back
    case Done = 'done';             // upgraded to a newer version
    case UpToDate = 'up_to_date';   // already on the latest - nothing to do
    case Failed = 'failed';

    /** Still working - the UI shows a spinner for these. */
    public function inProgress(): bool
    {
        return in_array($this, [self::Queued, self::Checking, self::Downloading, self::Rebooting], true);
    }
}
