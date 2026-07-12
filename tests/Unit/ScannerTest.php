<?php

namespace Tests\Unit;

use App\Services\Discovery\Scanner;
use PHPUnit\Framework\TestCase;

class ScannerTest extends TestCase
{
    public function test_expands_a_slash_30_to_two_usable_hosts(): void
    {
        $hosts = (new Scanner)->hosts('10.0.0.0/30');

        $this->assertSame(['10.0.0.1', '10.0.0.2'], $hosts);
    }

    public function test_excludes_network_and_broadcast_for_a_slash_24(): void
    {
        $hosts = (new Scanner)->hosts('192.168.1.0/24');

        $this->assertCount(254, $hosts);
        $this->assertSame('192.168.1.1', $hosts[0]);
        $this->assertSame('192.168.1.254', $hosts[253]);
        $this->assertNotContains('192.168.1.0', $hosts);   // network
        $this->assertNotContains('192.168.1.255', $hosts); // broadcast
    }

    public function test_slash_31_and_slash_32_keep_every_address(): void
    {
        $this->assertSame(['10.0.0.0', '10.0.0.1'], (new Scanner)->hosts('10.0.0.0/31'));
        $this->assertSame(['10.0.0.7'], (new Scanner)->hosts('10.0.0.7/32'));
    }

    public function test_normalises_a_non_aligned_address_to_its_network(): void
    {
        // .139/24 -> the .0/24 network's hosts.
        $hosts = (new Scanner)->hosts('10.80.111.139/24');

        $this->assertSame('10.80.111.1', $hosts[0]);
        $this->assertContains('10.80.111.139', $hosts);
    }

    public function test_caps_the_host_count(): void
    {
        $hosts = (new Scanner(maxHosts: 10))->hosts('10.0.0.0/24');

        $this->assertCount(10, $hosts);
        $this->assertSame('10.0.0.1', $hosts[0]);
    }

    public function test_invalid_cidrs_yield_no_hosts(): void
    {
        $scanner = new Scanner;

        $this->assertSame([], $scanner->hosts('not-a-cidr'));
        $this->assertSame([], $scanner->hosts('10.0.0.0'));        // no prefix
        $this->assertSame([], $scanner->hosts('10.0.0.0/33'));     // prefix out of range
        $this->assertSame([], $scanner->hosts('999.0.0.0/24'));    // bad octet
        $this->assertSame([], $scanner->hosts('2001:db8::/64'));   // IPv6 out of scope
    }

    public function test_usable_count(): void
    {
        $this->assertSame(254, Scanner::usableCount('10.0.0.0/24'));
        $this->assertSame(2, Scanner::usableCount('10.0.0.0/30'));
        $this->assertSame(2, Scanner::usableCount('10.0.0.0/31'));
        $this->assertSame(1, Scanner::usableCount('10.0.0.0/32'));
        $this->assertSame(0, Scanner::usableCount('garbage'));
    }

    public function test_is_valid(): void
    {
        $this->assertTrue(Scanner::isValid('10.0.0.0/24'));
        $this->assertTrue(Scanner::isValid('0.0.0.0/0'));
        $this->assertFalse(Scanner::isValid('10.0.0.0'));
        $this->assertFalse(Scanner::isValid('10.0.0.0/'));
        $this->assertFalse(Scanner::isValid('10.0.0.0/abc'));
        $this->assertFalse(Scanner::isValid('10.0.0.256/24'));
    }

    public function test_is_scannable_allows_private_and_public_ranges(): void
    {
        $this->assertTrue(Scanner::isScannable('10.0.0.0/16'));
        $this->assertTrue(Scanner::isScannable('192.168.1.0/24'));
        $this->assertTrue(Scanner::isScannable('172.16.0.0/12'));
        $this->assertTrue(Scanner::isScannable('8.0.0.0/8')); // public /8 - MSPs/ISPs may own one
    }

    public function test_is_scannable_rejects_reserved_ranges_regardless_of_size(): void
    {
        $this->assertFalse(Scanner::isScannable('127.0.0.0/8'));    // loopback
        $this->assertFalse(Scanner::isScannable('169.254.0.0/16')); // link-local / cloud metadata
        $this->assertFalse(Scanner::isScannable('0.0.0.0/8'));      // "this network"
        $this->assertFalse(Scanner::isScannable('224.0.0.0/4'));    // multicast
        $this->assertFalse(Scanner::isScannable('169.254.169.254/32')); // the metadata IP itself
    }

    public function test_is_scannable_rejects_ranges_broader_than_a_slash_8(): void
    {
        $this->assertFalse(Scanner::isScannable('1.0.0.0/7'));
        $this->assertFalse(Scanner::isScannable('0.0.0.0/0'));   // still syntactically valid per isValid()
        $this->assertTrue(Scanner::isScannable('10.0.0.0/8'));   // exactly the floor - allowed
    }

    public function test_is_scannable_rejects_invalid_syntax_too(): void
    {
        $this->assertFalse(Scanner::isScannable('not-a-cidr'));
        $this->assertFalse(Scanner::isScannable('10.0.0.0/33'));
    }
}
