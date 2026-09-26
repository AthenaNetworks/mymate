<?php

namespace App\Support;

/**
 * Parse + validate the origins allowed to embed the public wallboard in an iframe (GitHub #15).
 * Each one ends up verbatim in a CSP `frame-ancestors` directive, so this is deliberately strict:
 *
 *   - `scheme://host[:port]` only, scheme http or https. No path, query, fragment or userinfo.
 *   - host is a DNS name, an IPv4 address, or a bracketed IPv6 address.
 *   - one leading `*.` wildcard label is allowed (`https://*.example.com`) and matches any
 *     subdomain of example.com, same as CSP host-source matching. It needs at least two labels
 *     after it, so `*.com` and a bare `*` are refused - those would let almost anyone frame it.
 *   - CSP keywords (`'self'`, `*`, `https:`) are refused; list the real origins instead.
 *
 * Anything that isn't one of those comes back as null, so a bad value can never inject another
 * directive (`;`) or a wider source into the header.
 */
class FrameAncestors
{
    /** Hard cap on the list, it's a header after all. */
    public const MAX = 20;

    /** Normalise one origin (lowercase, no trailing slash), or null if it isn't allowed. */
    public static function normalise(string $origin): ?string
    {
        $origin = strtolower(trim($origin));
        $origin = rtrim($origin, '/');
        if ($origin === '' || strlen($origin) > 255) {
            return null;
        }

        if (! preg_match('#^(https?)://(\[[0-9a-f:.]+\]|[a-z0-9*.-]+)(?::(\d{1,5}))?$#', $origin, $m)) {
            return null;
        }
        [, $scheme, $host] = $m;
        $port = $m[3] ?? '';

        if ($port !== '' && ((int) $port < 1 || (int) $port > 65535)) {
            return null;
        }

        if (str_starts_with($host, '[')) {
            if (filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                return null;
            }
        } else {
            $wild = str_starts_with($host, '*.');
            $name = $wild ? substr($host, 2) : $host;
            // Only that one leading wildcard - no `*` anywhere else.
            if (str_contains($name, '*')) {
                return null;
            }
            $labels = explode('.', $name);
            foreach ($labels as $label) {
                if (! preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $label)) {
                    return null;
                }
            }
            if ($wild && count($labels) < 2) {
                return null; // *.com and friends
            }
            if ($wild && filter_var($name, FILTER_VALIDATE_IP) !== false) {
                return null; // *.10.0.0.1 is nonsense
            }
        }

        return $scheme.'://'.$host.($port !== '' ? ':'.(int) $port : '');
    }

    /**
     * Clean a list: normalised, de-duped, invalid entries dropped.
     *
     * @param  iterable<mixed>  $origins
     * @return list<string>
     */
    public static function clean(iterable $origins): array
    {
        $out = [];
        foreach ($origins as $o) {
            if (is_string($o) && ($n = self::normalise($o)) !== null) {
                $out[$n] = true;
            }
        }

        return array_slice(array_keys($out), 0, self::MAX);
    }
}
