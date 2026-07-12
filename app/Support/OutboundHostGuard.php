<?php

namespace App\Support;

/**
 * Guards server-initiated outbound requests to operator-supplied targets (alert
 * webhooks, SMTP settings) to block SSRF. Resolves the host and rejects
 * loopback, link-local (incl. the cloud metadata address 169.254.169.254), "this
 * network", and reserved/multicast ranges - i.e. PHP's FILTER_FLAG_NO_RES_RANGE
 * semantics, applied to a *resolved* IP so a hostname can't launder around it.
 *
 * RFC1918 and public ranges are deliberately allowed: an internal-IP Messenger
 * webhook and an internal SMTP relay are both legitimate, already-shipped
 * use cases - this only blocks ranges with zero legitimate use as an alert/mail
 * destination. Called at both save-time (validation) and send-time (immediately
 * before the request fires) to close the DNS-rebinding gap between the two.
 */
class OutboundHostGuard
{
    public static function isSafeUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' && self::isSafeHost($host);
    }

    public static function isSafeHost(string $host): bool
    {
        $ips = self::resolve($host);

        if ($ips === []) {
            return true; // unresolvable - nothing reachable to flag; the send will fail on its own
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
            if (self::isMulticast($ip)) {
                return false;
            }
        }

        return true;
    }

    /**
     * FILTER_FLAG_NO_RES_RANGE covers 240.0.0.0/4 but not 224.0.0.0/4 (IPv4 multicast),
     * and does not cover ff00::/8 (IPv6 multicast) either - add both explicitly.
     */
    private static function isMulticast(string $ip): bool
    {
        if (($long = ip2long($ip)) !== false) {
            return ($long & 0xF0000000) === 0xE0000000; // 224.0.0.0/4
        }

        $bin = @inet_pton($ip);

        return $bin !== false && strlen($bin) === 16 && ord($bin[0]) === 0xFF; // ff00::/8
    }

    /** @return list<string> */
    private static function resolve(string $host): array
    {
        // parse_url() leaves brackets on an IPv6 literal (e.g. "[::1]") - strip them
        // before validation, or filter_var() rejects a genuinely-literal address.
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        // curl/most HTTP clients accept inet_aton-style alternate IPv4 notation
        // (decimal "2130706433", octal "0177.0.0.1", hex "0x7f.0.0.1", short-form
        // "127.1") that filter_var() does NOT recognise as an IP - without this,
        // such a host falls through to dns_get_record(), which fails to resolve
        // it as a hostname, and the "unresolvable -> safe" fail-open below would
        // incorrectly let it through even though it resolves to 127.0.0.1 in
        // practice. Confirmed via `curl -v http://2130706433/` etc.
        if (($numeric = self::parseInetAtonStyle($host)) !== null) {
            return [$numeric];
        }

        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if ($records === false || $records === []) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (array $r): ?string => $r['ip'] ?? $r['ipv6'] ?? null,
            $records,
        )));
    }

    /** Parses a dotted/decimal/octal/hex IPv4 host the way inet_aton()-based resolvers do. */
    private static function parseInetAtonStyle(string $host): ?string
    {
        if ($host === '' || ! preg_match('/^[0-9a-fA-Fx.]+$/', $host)) {
            return null;
        }

        $parts = explode('.', $host);
        if (count($parts) > 4 || in_array('', $parts, true)) {
            return null;
        }

        $values = [];
        foreach ($parts as $part) {
            $value = self::parseUnsignedPart($part);
            if ($value === null) {
                return null;
            }
            $values[] = $value;
        }

        $count = count($values);
        foreach (array_slice($values, 0, -1) as $v) {
            if ($v > 0xFF) {
                return null; // every part except the last must fit in one byte
            }
        }

        $last = $values[$count - 1];
        $lastMax = match ($count) {
            1 => 0xFFFFFFFF,
            2 => 0xFFFFFF,
            3 => 0xFFFF,
            default => 0xFF,
        };
        if ($last > $lastMax) {
            return null;
        }

        $ip = $last;
        for ($i = 0; $i < $count - 1; $i++) {
            $ip |= $values[$i] << (8 * (3 - $i));
        }

        return long2ip($ip & 0xFFFFFFFF);
    }

    /** One dot-separated part as hex ("0x..."), octal ("0..."), or decimal - else null. */
    private static function parseUnsignedPart(string $part): ?int
    {
        if (preg_match('/^0[xX]([0-9a-fA-F]+)$/', $part, $m)) {
            return hexdec($m[1]);
        }
        if (preg_match('/^0([0-7]*)$/', $part, $m)) {
            return $m[1] === '' ? 0 : octdec($m[1]);
        }
        if (preg_match('/^[1-9][0-9]*$/', $part)) {
            return (int) $part;
        }

        return null;
    }
}
