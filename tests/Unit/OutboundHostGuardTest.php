<?php

namespace Tests\Unit;

use App\Support\OutboundHostGuard;
use PHPUnit\Framework\TestCase;

class OutboundHostGuardTest extends TestCase
{
    /** All IP-literal cases - no DNS resolution, deterministic and offline-safe. */
    public function test_rejects_loopback_link_local_and_reserved_hosts(): void
    {
        $this->assertFalse(OutboundHostGuard::isSafeHost('127.0.0.1'));
        $this->assertFalse(OutboundHostGuard::isSafeHost('169.254.169.254')); // cloud metadata
        $this->assertFalse(OutboundHostGuard::isSafeHost('0.0.0.0'));
        $this->assertFalse(OutboundHostGuard::isSafeHost('224.0.0.1')); // multicast
        $this->assertFalse(OutboundHostGuard::isSafeHost('::1'));
    }

    /** A follow-up finding (2026-07-01): IPv6 multicast wasn't covered by the initial fix. */
    public function test_rejects_ipv6_link_local_and_multicast(): void
    {
        $this->assertFalse(OutboundHostGuard::isSafeHost('fe80::1'));  // link-local
        $this->assertFalse(OutboundHostGuard::isSafeHost('ff02::1'));  // multicast
        $this->assertFalse(OutboundHostGuard::isSafeHost('::ffff:127.0.0.1')); // v4-mapped loopback
    }

    public function test_allows_private_and_public_hosts(): void
    {
        // RFC1918 (and its IPv6 ULA equivalent) stay allowed - an internal Messenger
        // webhook / SMTP relay is legitimate.
        $this->assertTrue(OutboundHostGuard::isSafeHost('10.0.0.5'));
        $this->assertTrue(OutboundHostGuard::isSafeHost('192.168.1.1'));
        $this->assertTrue(OutboundHostGuard::isSafeHost('172.16.0.1'));
        $this->assertTrue(OutboundHostGuard::isSafeHost('8.8.8.8'));
        $this->assertTrue(OutboundHostGuard::isSafeHost('fc00::1'));
        $this->assertTrue(OutboundHostGuard::isSafeHost('2001:4860:4860::8888'));
    }

    public function test_url_variant_extracts_the_host_before_checking(): void
    {
        $this->assertFalse(OutboundHostGuard::isSafeUrl('http://127.0.0.1/hook'));
        $this->assertFalse(OutboundHostGuard::isSafeUrl('http://169.254.169.254/latest/meta-data/'));
        $this->assertTrue(OutboundHostGuard::isSafeUrl('http://10.0.0.5:8000/hook'));
        $this->assertTrue(OutboundHostGuard::isSafeUrl('https://8.8.8.8/hook'));
    }

    public function test_a_url_with_no_host_is_not_safe(): void
    {
        $this->assertFalse(OutboundHostGuard::isSafeUrl('not-a-url'));
    }

    /**
     * A follow-up finding (2026-07-01): curl (which Guzzle wraps) resolves alternate
     * IPv4 notation to the real address - confirmed via `curl -v http://2130706433/`
     * connecting to 127.0.0.1 - but filter_var()/dns_get_record() didn't recognise
     * these as IPs, so they fell through the old fail-open "unresolvable" path.
     */
    public function test_rejects_obfuscated_loopback_notation(): void
    {
        $this->assertFalse(OutboundHostGuard::isSafeUrl('http://2130706433/x'));   // decimal
        $this->assertFalse(OutboundHostGuard::isSafeUrl('http://0177.0.0.1/x'));   // octal
        $this->assertFalse(OutboundHostGuard::isSafeUrl('http://0x7f.0.0.1/x'));   // hex
        $this->assertFalse(OutboundHostGuard::isSafeUrl('http://127.1/x'));        // short-form
        $this->assertFalse(OutboundHostGuard::isSafeUrl('http://0x7F000001/x'));   // hex, single-part
    }

    /** Bracket-wrapped IPv6 URL hosts (as parse_url() returns them) must still be checked. */
    public function test_rejects_bracketed_ipv6_url_hosts(): void
    {
        $this->assertFalse(OutboundHostGuard::isSafeUrl('http://[::1]/x'));
        $this->assertFalse(OutboundHostGuard::isSafeUrl('http://[::ffff:127.0.0.1]/x'));
        $this->assertFalse(OutboundHostGuard::isSafeUrl('http://[fe80::1]/x'));
        $this->assertTrue(OutboundHostGuard::isSafeUrl('http://[fc00::1]/x')); // ULA stays allowed
    }

    /** A real hostname that happens to look hex-ish must never be mistaken for a numeric IP. */
    public function test_does_not_misparse_a_real_hostname_as_numeric(): void
    {
        $this->assertTrue(OutboundHostGuard::isSafeHost('cafe.example.com'));
        $this->assertTrue(OutboundHostGuard::isSafeHost('hooks.slack.com'));
    }
}
