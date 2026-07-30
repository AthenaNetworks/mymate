<?php

namespace App\Enums;

/**
 * What kind of physical location a site is, for filtering and map styling. Purely a label -
 * nothing in the engine behaves differently per kind. Stored as a plain string (enum cast on
 * the model), so an unrecognised value from an import degrades to Other rather than failing.
 */
enum SiteKind: string
{
    case Tower = 'tower';       // wireless tower / mast / rooftop
    case Cabinet = 'cabinet';   // fiber cabinet, OLT, splitter enclosure
    case Pop = 'pop';           // point of presence, exchange, data centre
    case Other = 'other';

    public static function parse(?string $value): self
    {
        return self::tryFrom(mb_strtolower(trim((string) $value))) ?? self::Other;
    }
}
