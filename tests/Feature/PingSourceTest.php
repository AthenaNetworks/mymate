<?php

namespace Tests\Feature;

use App\Services\Ping\FpingRunner;
use Tests\TestCase;

/**
 * fping source-address support (GitHub #11) - ping FROM a specific local address to test a
 * particular path (e.g. a customer/WAN interface reaching the internet).
 */
class PingSourceTest extends TestCase
{
    private function args(FpingRunner $runner): array
    {
        return (new \ReflectionMethod(FpingRunner::class, 'commandArgs'))->getClosure($runner)();
    }

    public function test_no_source_flag_by_default(): void
    {
        $this->assertNotContains('-S', $this->args(new FpingRunner()));
    }

    public function test_source_flag_and_address_added_when_configured(): void
    {
        $out = $this->args(new FpingRunner(source: '203.0.113.9'));

        $this->assertContains('-S', $out);
        $this->assertSame('203.0.113.9', $out[array_search('-S', $out, true) + 1]);
    }

    public function test_provider_wires_the_configured_source(): void
    {
        config(['mymate.ping.source' => '198.51.100.7']);
        $runner = app(\App\Services\Ping\Pinger::class);
        $this->assertInstanceOf(FpingRunner::class, $runner);
        $this->assertContains('-S', $this->args($runner));
    }
}
