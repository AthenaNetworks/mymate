<?php

namespace Tests\Unit\Tools;

use App\Services\Tools\Targets;
use PHPUnit\Framework\TestCase;

class TargetsTest extends TestCase
{
    public function test_accepts_ipv4_ipv6_and_hostnames_but_rejects_junk(): void
    {
        $this->assertTrue(Targets::isHost('1.1.1.1'));
        $this->assertTrue(Targets::isHost('2606:4700:4700::1111'));
        $this->assertTrue(Targets::isHost('example.com'));
        $this->assertTrue(Targets::isHost('a-host.sub.example.co.uk'));

        $this->assertFalse(Targets::isHost(''));
        $this->assertFalse(Targets::isHost('bad;rm -rf /'));
        $this->assertFalse(Targets::isHost('-oProxyCommand=x'));
        $this->assertFalse(Targets::isHost('has space'));
    }

    public function test_cidr_validation(): void
    {
        $this->assertTrue(Targets::isCidr('192.168.1.0/24'));
        $this->assertTrue(Targets::isCidr('10.0.0.0/8'));
        $this->assertFalse(Targets::isCidr('192.168.1.1')); // no prefix
        $this->assertFalse(Targets::isCidr('192.168.1.0/33')); // out of range
        $this->assertFalse(Targets::isCidr('2001:db8::/48')); // v6 not swept
    }

    public function test_host_count_drops_network_and_broadcast_except_for_narrow_prefixes(): void
    {
        $this->assertSame(254, Targets::hostCount('192.168.1.0/24'));
        $this->assertSame(2, Targets::hostCount('192.168.1.0/30'));
        $this->assertSame(2, Targets::hostCount('192.168.1.0/31')); // point-to-point: both usable
        $this->assertSame(1, Targets::hostCount('192.168.1.5/32'));
    }

    public function test_enumerate_lists_hosts_and_refuses_oversized_ranges(): void
    {
        $this->assertSame(['192.168.1.1', '192.168.1.2'], Targets::enumerate('192.168.1.0/30', 1024));

        // /29 = 6 usable hosts (.9 - .14), skipping the .8 network and .15 broadcast.
        $this->assertSame(
            ['192.168.5.9', '192.168.5.10', '192.168.5.11', '192.168.5.12', '192.168.5.13', '192.168.5.14'],
            Targets::enumerate('192.168.5.8/29', 1024),
        );

        // A /8 is far over the cap - callers turn this null into a 422 rather than sweeping 16M hosts.
        $this->assertNull(Targets::enumerate('10.0.0.0/8', 1024));
    }
}
