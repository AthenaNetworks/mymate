<?php

namespace App\Enums;

/**
 * How a Dude import reconciles with existing data:
 *  - Upsert: keep existing config, update-or-insert on natural keys (default).
 *  - Fresh:  wipe the import-managed domain first (devices + cascades, credentials,
 *            maps, interface_samples), then insert. Auth/settings/alerts are kept.
 */
enum ImportMode: string
{
    case Upsert = 'upsert';
    case Fresh = 'fresh';
}
