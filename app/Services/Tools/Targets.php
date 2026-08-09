<?php

namespace App\Services\Tools;

/**
 * Input validation + IPv4 CIDR maths for the Tools page. Everything a tool aims at passes
 * through here first: a ping/trace/port-scan target must be a real IP or hostname, and a
 * sweep must be an IPv4 CIDR small enough to finish inside a job. Keeping this in one place
 * means the FormRequest-style checks in the controller and the jobs agree on what's legal.
 */
class Targets
{
    /** A single ping/trace/port-scan target: an IPv4/IPv6 literal or a DNS hostname. */
    public static function isHost(string $value): bool
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 253) {
            return false;
        }

        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        // Hostname: dot-separated labels, each 1-63 of [a-z0-9-], not starting/ending with a hyphen.
        return (bool) preg_match(
            '/^(?=.{1,253}$)(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))*\.?$/i',
            $value,
        );
    }

    /** An IPv4 CIDR like 192.168.1.0/24. Sweep only - enumerating IPv6 space is a non-starter. */
    public static function isCidr(string $value): bool
    {
        return self::parseCidr($value) !== null;
    }

    /** How many addresses a CIDR spans (usable hosts, i.e. minus network+broadcast for /30 and wider). */
    public static function hostCount(string $cidr): int
    {
        $parsed = self::parseCidr($cidr);
        if ($parsed === null) {
            return 0;
        }

        [, $prefix] = $parsed;
        $total = 1 << (32 - $prefix);

        return $prefix >= 31 ? $total : $total - 2; // /31 + /32 have no network/broadcast to drop
    }

    /**
     * Expand a CIDR to the list of host IPs a sweep should ping, or null if it's larger than
     * $cap (the caller turns that into a 422 - we don't silently truncate a range).
     *
     * @return list<string>|null
     */
    public static function enumerate(string $cidr, int $cap): ?array
    {
        $parsed = self::parseCidr($cidr);
        if ($parsed === null || self::hostCount($cidr) > $cap) {
            return null;
        }

        [$base, $prefix] = $parsed;
        $network = $base & (-1 << (32 - $prefix) & 0xFFFFFFFF);
        $total = 1 << (32 - $prefix);

        [$first, $last] = $prefix >= 31
            ? [0, $total - 1]          // /31, /32: every address is a host
            : [1, $total - 2];          // otherwise skip .0 network and the broadcast

        $hosts = [];
        for ($i = $first; $i <= $last; $i++) {
            $hosts[] = long2ip($network + $i);
        }

        return $hosts;
    }

    /**
     * @return array{0: int, 1: int}|null  [base ip as uint32, prefix length] or null if not a v4 CIDR
     */
    private static function parseCidr(string $value): ?array
    {
        $value = trim($value);
        if (! str_contains($value, '/')) {
            return null;
        }

        [$ip, $prefix] = explode('/', $value, 2);
        if (! ctype_digit($prefix) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }

        $prefix = (int) $prefix;
        if ($prefix < 0 || $prefix > 32) {
            return null;
        }

        return [ip2long($ip), $prefix];
    }
}
