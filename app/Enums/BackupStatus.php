<?php

namespace App\Enums;

/**
 * Outcome of a device config-backup run. Backups are performed by
 * the external **Rusted** engine over SSH; this mirrors its per-run result onto the
 * device so the UI can show "last backup: ok, 2 min ago" without hitting Rusted on
 * every render. `null` on the device means "never backed up".
 */
enum BackupStatus: string
{
    case Pending = 'pending';       // dispatched to the backup queue, not finished yet
    case Ok = 'ok';                 // Rusted captured a config that changed (new commit)
    case Unchanged = 'unchanged';   // captured, but identical to the last stored config
    case Failed = 'failed';         // Rusted could not reach/back up the device

    /**
     * Map a Rusted history/backup `status` string onto our enum. Rusted reports
     * `success` | `unchanged` | `failed`; anything unrecognised is treated as failed
     * (never silently "ok").
     */
    public static function fromRusted(?string $status): self
    {
        return match ($status) {
            'success', 'ok', 'changed' => self::Ok,
            'unchanged' => self::Unchanged,
            'pending' => self::Pending,
            default => self::Failed,
        };
    }

    /** A backup is still running - the UI shows a spinner for this. */
    public function inProgress(): bool
    {
        return $this === self::Pending;
    }
}
