<?php

namespace App\Services\Discovery;

/**
 * Expands an IPv4 CIDR into its usable host addresses.
 *
 * Pure + side-effect free so it's trivially unit-testable. The result is **capped**
 * at `maxHosts` so an over-broad range (e.g. a /8 = 16M hosts) can't blow up a sweep
 * - the caller logs when the cap bites (no silent truncation). IPv6 is out of v1
 * scope (expansion is infeasible); an IPv6/invalid CIDR yields an empty list.
 *
 * {@see isScannable()} is the *registration*-time gate - rejects a CIDR
 * that overlaps a reserved range or is broader than a /8, independent of the maxHosts
 * sweep cap above.
 */
class Scanner
{
    public function __construct(private int $maxHosts = 4096) {}

    /**
     * Usable host IPs in the CIDR, capped at maxHosts. Network + broadcast addresses
     * are excluded for /30 and larger; /31 (RFC 3021 p2p) and /32 (single host) keep
     * every address.
     *
     * @return list<string>
     */
    public function hosts(string $cidr): array
    {
        $parsed = self::parse($cidr);
        if ($parsed === null) {
            return [];
        }
        [$network, $prefix] = $parsed;

        $count = 2 ** (32 - $prefix);
        $start = 0;
        $end = $count - 1;
        if ($prefix <= 30) {
            $start = 1;          // skip network address
            $end = $count - 2;   // skip broadcast address
        }

        $hosts = [];
        for ($i = $start; $i <= $end; $i++) {
            $hosts[] = long2ip($network + $i);
            if (count($hosts) >= $this->maxHosts) {
                break;
            }
        }

        return $hosts;
    }

    /** How many usable hosts the CIDR contains (before the cap); 0 if invalid. */
    public static function usableCount(string $cidr): int
    {
        $parsed = self::parse($cidr);
        if ($parsed === null) {
            return 0;
        }
        $prefix = $parsed[1];
        $count = 2 ** (32 - $prefix);

        return $prefix <= 30 ? max(0, $count - 2) : $count;
    }

    /** Whether a string is a syntactically valid IPv4 CIDR (e.g. "10.0.0.0/24"). */
    public static function isValid(string $cidr): bool
    {
        if (! str_contains($cidr, '/')) {
            return false;
        }
        [$ip, $prefix] = explode('/', trim($cidr), 2);

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }
        if (! ctype_digit($prefix)) {
            return false;
        }
        $p = (int) $prefix;

        return $p >= 0 && $p <= 32;
    }

    /**
     * Ranges with zero legitimate use as a *scan target* - loopback,
     * link-local (incl. the cloud metadata address 169.254.169.254), "this network", and
     * reserved/multicast. RFC1918 and public ranges are deliberately allowed: this app is
     * built for MSPs/ISPs monitoring their own (sometimes public-IP) infrastructure.
     *
     * @var list<array{0:string,1:int}>
     */
    private const RESERVED_RANGES = [
        ['0.0.0.0', 8],
        ['127.0.0.0', 8],
        ['169.254.0.0', 16],
        ['224.0.0.0', 3],
    ];

    /** Minimum allowed prefix length - caps the blast radius of a single registration. */
    private const MIN_PREFIX = 8;

    /**
     * Whether a CIDR is not just syntactically valid but safe to register as a *scan
     * target*: no broader than a /8, and doesn't overlap a reserved range. `isValid()`
     * (syntax only) is unchanged and still used internally by `hosts()`/`usableCount()`.
     */
    public static function isScannable(string $cidr): bool
    {
        if (! self::isValid($cidr)) {
            return false;
        }
        [, $prefixStr] = explode('/', trim($cidr), 2);
        if ((int) $prefixStr < self::MIN_PREFIX) {
            return false;
        }

        return ! self::overlapsReservedRange($cidr);
    }

    private static function overlapsReservedRange(string $cidr): bool
    {
        $parsed = self::parse($cidr);
        if ($parsed === null) {
            return false;
        }
        [$network, $prefix] = $parsed;
        $rangeEnd = $network + (2 ** (32 - $prefix)) - 1;

        foreach (self::RESERVED_RANGES as [$resIp, $resPrefix]) {
            $resMask = $resPrefix === 0 ? 0 : ((-1 << (32 - $resPrefix)) & 0xFFFFFFFF);
            $resNetwork = ((int) ip2long($resIp)) & $resMask;
            $resEnd = $resNetwork + (2 ** (32 - $resPrefix)) - 1;

            if ($network <= $resEnd && $resNetwork <= $rangeEnd) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0:int,1:int}|null [networkLong, prefix] for a valid IPv4 CIDR, else null.
     */
    private static function parse(string $cidr): ?array
    {
        if (! self::isValid($cidr)) {
            return null;
        }
        [$ip, $prefixStr] = explode('/', trim($cidr), 2);
        $prefix = (int) $prefixStr;

        // 32-bit mask as a positive int (PHP ints are 64-bit, so & 0xFFFFFFFF clamps it).
        $mask = $prefix === 0 ? 0 : ((-1 << (32 - $prefix)) & 0xFFFFFFFF);
        $network = ((int) ip2long($ip)) & $mask;

        return [$network, $prefix];
    }
}
