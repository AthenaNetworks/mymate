<?php

namespace App\Services\Probes;

use App\Models\Probe;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * HTTP(S) probe: request a URL and decide up/down from the status code (and optionally a keyword
 * in the body), timing the round trip. For HTTPS it also reads the TLS certificate expiry, so one
 * probe covers "is the service answering" and "is the cert about to lapse".
 *
 * Redirects are NOT followed - a 301/302 is a real answer and the operator's expected-status range
 * decides whether that counts as up, rather than us chasing it somewhere unexpected.
 */
class HttpProbe
{
    public function run(Probe $probe): ProbeResult
    {
        $config = $probe->config ?? [];
        $url = (string) ($config['url'] ?? '');
        if ($url === '') {
            return ProbeResult::down('no url configured');
        }

        $method = strtoupper((string) ($config['method'] ?? 'GET'));
        $expect = (string) ($config['expect_status'] ?? '200-399');
        $keyword = trim((string) ($config['expect_body'] ?? ''));
        $verifyTls = (bool) ($config['verify_tls'] ?? true);
        $timeout = max(1, (int) ceil($probe->timeout_ms / 1000));
        $wantsBody = $keyword !== '' && $method !== 'HEAD';

        $start = microtime(true);
        try {
            $request = Http::timeout($timeout)
                ->withoutRedirecting()
                ->withOptions(['verify' => $verifyTls]);

            $response = match ($method) {
                'HEAD' => $request->head($url),
                'POST' => $request->post($url),
                default => $request->get($url),
            };
        } catch (\Throwable $e) {
            return ProbeResult::down($this->reason($e), (microtime(true) - $start) * 1000);
        }
        $latency = (microtime(true) - $start) * 1000;

        $status = $response->status();
        if (! self::statusMatches($status, $expect)) {
            return ProbeResult::down("HTTP {$status}", $latency);
        }
        if ($wantsBody && stripos($response->body(), $keyword) === false) {
            return ProbeResult::down("HTTP {$status}, missing \"{$keyword}\"", $latency);
        }

        return ProbeResult::up($latency, "HTTP {$status}", $this->certExpiry($url, $timeout));
    }

    /**
     * Does an HTTP status satisfy an expected-status expression? Accepts a comma-separated list of
     * exact codes ("200,204"), inclusive ranges ("200-399") and single-digit wildcards ("2xx").
     */
    public static function statusMatches(int $status, string $expect): bool
    {
        foreach (array_filter(array_map('trim', explode(',', $expect))) as $part) {
            $part = strtolower($part);
            if (str_contains($part, '-')) {
                [$lo, $hi] = array_map('intval', explode('-', $part, 2));
                if ($status >= $lo && $status <= $hi) {
                    return true;
                }
            } elseif (str_contains($part, 'x')) {
                // "2xx" -> the status shares the described leading digits.
                $prefix = rtrim($part, 'x');
                if ($prefix !== '' && str_starts_with((string) $status, $prefix) && strlen((string) $status) === strlen($part)) {
                    return true;
                }
            } elseif (is_numeric($part) && (int) $part === $status) {
                return true;
            }
        }

        return false;
    }

    /** Best-effort TLS certificate expiry for an https URL; null for http or on any failure. */
    private function certExpiry(string $url, int $timeout): ?CarbonImmutable
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === '') {
            return null;
        }
        $host = $parts['host'];
        $port = (int) ($parts['port'] ?? 443);

        try {
            $context = stream_context_create(['ssl' => [
                'capture_peer_cert' => true,
                'verify_peer' => false,
                'verify_peer_name' => false,
                'SNI_enabled' => true,
                'peer_name' => $host,
            ]]);
            $client = @stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
            if ($client === false) {
                return null;
            }
            $params = stream_context_get_params($client);
            fclose($client);
            $cert = $params['options']['ssl']['peer_certificate'] ?? null;
            if ($cert === null) {
                return null;
            }
            $parsed = openssl_x509_parse($cert);
            $validTo = $parsed['validTo_time_t'] ?? null;

            return $validTo ? CarbonImmutable::createFromTimestamp($validTo) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** A short, safe reason from a transport exception (no stack, no secrets). */
    private function reason(\Throwable $e): string
    {
        $msg = $e->getMessage();
        if (stripos($msg, 'timed out') !== false || stripos($msg, 'timeout') !== false) {
            return 'timed out';
        }
        if (stripos($msg, 'could not resolve') !== false || stripos($msg, 'name resolution') !== false) {
            return 'dns lookup failed';
        }
        if (stripos($msg, 'refused') !== false) {
            return 'connection refused';
        }
        if (stripos($msg, 'certificate') !== false || stripos($msg, 'ssl') !== false) {
            return 'tls error';
        }

        return 'request failed';
    }
}
