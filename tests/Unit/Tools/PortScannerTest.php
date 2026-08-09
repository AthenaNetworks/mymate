<?php

namespace Tests\Unit\Tools;

use App\Services\Tools\PortScanner;
use PHPUnit\Framework\TestCase;

class PortScannerTest extends TestCase
{
    public function test_reports_a_listening_port_open_and_a_free_port_closed(): void
    {
        // A real listener on a kernel-chosen port - deterministically "open".
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($server, "could not bind a test listener: {$errstr}");
        $openPort = (int) explode(':', stream_socket_get_name($server, false))[1];

        // Bind then immediately release a second port so it's known-free -> connect refused -> "closed".
        $tmp = stream_socket_server('tcp://127.0.0.1:0', $e, $s);
        $closedPort = (int) explode(':', stream_socket_get_name($tmp, false))[1];
        fclose($tmp);

        $states = [];
        (new PortScanner(500, 16))->scan(
            '127.0.0.1',
            [$openPort, $closedPort],
            function (int $port, string $state) use (&$states) {
                $states[$port] = $state;
            },
            fn () => false,
        );

        fclose($server);

        $this->assertSame('open', $states[$openPort]);
        $this->assertSame('closed', $states[$closedPort]);
    }

    public function test_stop_callback_halts_before_scanning_further_batches(): void
    {
        $scanned = 0;
        (new PortScanner(300, 1))->scan( // concurrency 1 => one port per batch, stop checked between
            '127.0.0.1',
            [1, 2, 3, 4, 5],
            function () use (&$scanned) {
                $scanned++;
            },
            function () use (&$scanned) {
                return $scanned >= 1; // cancel as soon as the first port resolves (by-ref, not a frozen arrow fn)
            },
        );

        $this->assertLessThan(5, $scanned);
    }
}
