<?php

namespace App\Services\Tools;

/**
 * A pure-PHP TCP connect scan - no nmap, no raw sockets, no root. It opens a batch of
 * non-blocking connects at once and waits for them to resolve with stream_select, so a
 * wide port list finishes in roughly one timeout window instead of one-per-port. A
 * connect scan only proves something accepted a TCP handshake; it can't tell you what
 * that something is, hence the best-effort service name is just a well-known-port label.
 *
 * States reported per port:
 *   open      the handshake completed - something is listening
 *   closed    the host actively refused (RST) - reachable, nothing on that port
 *   filtered  no answer inside the timeout - dropped by a firewall, or host down
 */
class PortScanner
{
    public function __construct(
        private readonly int $timeoutMs,
        private readonly int $concurrency,
    ) {}

    /**
     * Scan $ports on $host. Invokes $onResult(int $port, string $state, ?string $service) as
     * each port resolves, and checks $shouldStop() between batches so a cancelled run stops
     * promptly instead of grinding through every remaining port.
     *
     * @param  list<int>  $ports
     * @param  callable(int, string, ?string): void  $onResult
     * @param  callable(): bool  $shouldStop
     */
    public function scan(string $host, array $ports, callable $onResult, callable $shouldStop): void
    {
        $target = str_contains($host, ':') ? "[{$host}]" : $host; // bracket a bare IPv6 literal

        foreach (array_chunk($ports, max(1, $this->concurrency)) as $batch) {
            if ($shouldStop()) {
                return;
            }

            $this->scanBatch($target, $batch, $onResult);
        }
    }

    /**
     * @param  list<int>  $ports
     * @param  callable(int, string, ?string): void  $onResult
     */
    private function scanBatch(string $target, array $ports, callable $onResult): void
    {
        /** @var array<int, resource> $pending  port => socket still waiting to resolve */
        $pending = [];

        foreach ($ports as $port) {
            $sock = @stream_socket_client(
                "tcp://{$target}:{$port}",
                $errno,
                $errstr,
                0, // connect timeout is handled by our own select loop, not here
                STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT,
            );

            if ($sock === false) {
                $onResult($port, 'closed', ServiceNames::for($port)); // refused before we even waited
                continue;
            }

            stream_set_blocking($sock, false);
            $pending[$port] = $sock;
        }

        $deadline = microtime(true) + ($this->timeoutMs / 1000.0);

        while ($pending !== [] && microtime(true) < $deadline) {
            $write = array_values($pending);
            $read = $except = null;
            $remainingMs = (int) max(0, ($deadline - microtime(true)) * 1_000_000);

            // Sockets go writable both on a completed handshake AND on a refusal; we tell the
            // two apart by whether the peer name is now readable.
            $ready = @stream_select($read, $write, $except, 0, $remainingMs);
            if ($ready === false || $ready === 0) {
                break;
            }

            foreach ($write as $sock) {
                $port = (int) array_search($sock, $pending, true);
                $connected = @stream_socket_get_name($sock, true) !== false;
                $onResult($port, $connected ? 'open' : 'closed', ServiceNames::for($port));
                fclose($sock);
                unset($pending[$port]);
            }
        }

        // Anything still pending never answered inside the window - firewalled or host down.
        foreach ($pending as $port => $sock) {
            $onResult($port, 'filtered', ServiceNames::for($port));
            fclose($sock);
        }
    }
}
