<?php

namespace App\Enums;

/** Lifecycle of a Dude import run. */
enum ImportStatus: string
{
    case Pending = 'pending';
    case Extracting = 'extracting';   // running dude-extract.py -> CSVs
    case Importing = 'importing';     // upserting CSVs into My Mate
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Failed || $this === self::Cancelled;
    }
}
