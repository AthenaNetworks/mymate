<?php

namespace App\Services\Probes;

use App\Models\Probe;

/**
 * TCP probe: open a socket to host:port and call it up if the connection establishes, timing how
 * long the handshake took. The host defaults to the device's management address, so a probe can be
 * as simple as "port 443" on the device you're already tracking.
 */
class TcpProbe
{
    public function run(Probe $probe): ProbeResult
    {
        $config = $probe->config ?? [];
        $host = trim((string) ($config['host'] ?? '')) ?: (string) $probe->device?->mgmt_ip;
        $port = (int) ($config['port'] ?? 0);

        if ($host === '') {
            return ProbeResult::down('no host (device has no management address)');
        }
        if ($port < 1 || $port > 65535) {
            return ProbeResult::down('invalid port');
        }

        $timeout = max(1, $probe->timeout_ms / 1000);

        $start = microtime(true);
        $client = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
        $latency = (microtime(true) - $start) * 1000;

        if ($client === false) {
            $reason = $errno === SOCKET_ECONNREFUSED || stripos($errstr, 'refused') !== false
                ? 'connection refused'
                : ($errstr !== '' ? 'unreachable' : 'timed out');

            return ProbeResult::down($reason, $latency);
        }

        fclose($client);

        return ProbeResult::up($latency, "port {$port} open");
    }
}
