<?php

namespace App\Services\Neighbors;

/**
 * SNMP octet strings as they reach us: printable text, raw bytes (this server's SNMP client),
 * "hex:<hex>" (a remote agent, which can't put raw bytes in JSON), or net-snmp's "AA BB CC" hex
 * dump. Chassis ids and CDP addresses are usually binary; names are text.
 */
final class Octets
{
    /** The raw bytes, or null for nothing. */
    public static function bytes(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (str_starts_with($value, 'hex:')) {
            $hex = substr($value, 4);

            return ctype_xdigit($hex) && strlen($hex) % 2 === 0 ? (string) hex2bin($hex) : null;
        }
        if (preg_match('/^[0-9A-Fa-f]{2}([ :][0-9A-Fa-f]{2})+[ ]?$/', $value) === 1) {
            return (string) hex2bin((string) preg_replace('/[ :]/', '', trim($value)));
        }

        return $value;
    }

    /** Printable text, or null when it's empty or binary. */
    public static function text(?string $value): ?string
    {
        if ($value === null || str_starts_with($value, 'hex:')) {
            return null;
        }
        $value = trim($value);

        return $value !== '' && self::printable($value) ? $value : null;
    }

    /**
     * An identifier to compare: a 6-byte value as a lowercase MAC (aa:bb:cc:dd:ee:ff), else the
     * text lowercased. Null when there's nothing usable.
     */
    public static function id(?string $value): ?string
    {
        $text = $value !== null ? trim($value) : '';
        if (preg_match('/^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$/', $text) === 1) {
            return strtolower(str_replace('-', ':', $text));
        }
        $bytes = self::bytes($value);
        if ($bytes === null) {
            return null;
        }
        if (strlen($bytes) === 6 && ! self::printable($bytes)) {
            return implode(':', str_split(bin2hex($bytes), 2));
        }
        if (self::printable($bytes)) {
            return mb_substr(strtolower(trim($bytes)), 0, 64) ?: null;
        }

        return mb_substr(bin2hex($bytes), 0, 64);
    }

    private static function printable(string $s): bool
    {
        return mb_check_encoding($s, 'UTF-8') && preg_match('/[\x00-\x08\x0E-\x1F\x7F]/', $s) !== 1;
    }
}
