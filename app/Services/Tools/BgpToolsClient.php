<?php

namespace App\Services\Tools;

use Illuminate\Support\Facades\Cache;

/**
 * Looks up an IP or ASN against bgp.tools' whois interface (their documented port-43
 * service, https://bgp.tools/kb/api) and parses the pipe-delimited verbose row into
 * fields. Results are cached - bgp.tools explicitly ask that their data not be hammered,
 * and the origin AS of an address rarely changes within the hour.
 *
 * The verbose ("-v") reply looks like:
 *   AS    | IP      | BGP Prefix  | CC | Registry | Allocated  | AS Name
 *   13335 | 1.1.1.1 | 1.1.1.0/24  | US | ARIN     | 2010-07-14 | Cloudflare, Inc.
 */
class BgpToolsClient
{
    /**
     * @return array{query: string, asn: ?string, name: ?string, prefix: ?string, ip: ?string, country: ?string, registry: ?string, allocated: ?string, raw: string}|null
     */
    public function lookup(string $input): ?array
    {
        $query = self::normalise($input);
        if ($query === null) {
            return null;
        }

        $minutes = (int) config('mymate.tools.bgp.cache_minutes', 60);

        return Cache::remember("tools:bgp:{$query}", now()->addMinutes($minutes), function () use ($query) {
            $raw = $this->whois($query);

            return $raw === null ? null : $this->parse($query, $raw);
        });
    }

    /** Normalise user input to what bgp.tools expects: a bare IP, or "as<number>" for an ASN. */
    private static function normalise(string $input): ?string
    {
        $input = trim($input);

        if (filter_var($input, FILTER_VALIDATE_IP) !== false) {
            return $input;
        }

        // "AS13335", "as13335" or a bare number all mean the same ASN.
        if (preg_match('/^(?:as)?(\d{1,10})$/i', $input, $m)) {
            return 'as'.$m[1];
        }

        return null;
    }

    private function whois(string $query): ?string
    {
        $host = (string) config('mymate.tools.bgp.host', 'bgp.tools');
        $port = (int) config('mymate.tools.bgp.port', 43);
        $timeout = (int) config('mymate.tools.bgp.timeout', 6);

        $sock = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if ($sock === false) {
            return null;
        }

        stream_set_timeout($sock, $timeout);

        // A leading space + "-v" asks their server for the verbose, column-headed answer.
        fwrite($sock, " -v {$query}\r\n");

        $out = '';
        while (! feof($sock)) {
            $chunk = fread($sock, 4096);
            if ($chunk === false) {
                break;
            }
            $out .= $chunk;

            $meta = stream_get_meta_data($sock);
            if ($meta['timed_out']) {
                break;
            }
        }

        fclose($sock);

        return $out === '' ? null : $out;
    }

    /**
     * @return array{query: string, asn: ?string, name: ?string, prefix: ?string, ip: ?string, country: ?string, registry: ?string, allocated: ?string, raw: string}
     */
    private function parse(string $query, string $raw): array
    {
        $rows = [];
        foreach (preg_split('/\r?\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue; // skip banner / comment lines
            }
            if (! str_contains($line, '|')) {
                continue;
            }
            $rows[] = array_map('trim', explode('|', $line));
        }

        $base = [
            'query' => $query, 'asn' => null, 'name' => null, 'prefix' => null,
            'ip' => null, 'country' => null, 'registry' => null, 'allocated' => null, 'raw' => trim($raw),
        ];

        // First pipe row is the header; the next is the data. Fall back to a lone data row.
        $header = $rows[0] ?? null;
        $data = $rows[1] ?? ($header && ! self::looksLikeHeader($header) ? $header : null);
        if ($header === null || $data === null) {
            return $base;
        }

        $map = [
            'as' => 'asn', 'ip' => 'ip', 'bgp prefix' => 'prefix', 'cc' => 'country',
            'registry' => 'registry', 'allocated' => 'allocated', 'as name' => 'name',
        ];
        foreach ($header as $i => $col) {
            $key = $map[strtolower($col)] ?? null;
            if ($key !== null && isset($data[$i]) && $data[$i] !== '') {
                $base[$key] = $data[$i];
            }
        }

        return $base;
    }

    /** @param list<string> $row */
    private static function looksLikeHeader(array $row): bool
    {
        return strtolower($row[0] ?? '') === 'as';
    }
}
