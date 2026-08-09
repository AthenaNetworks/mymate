<?php

namespace Tests\Unit\Tools;

use App\Services\Tools\BgpToolsClient;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class BgpToolsClientTest extends TestCase
{
    public function test_parses_the_verbose_pipe_delimited_row(): void
    {
        $raw = "AS      | IP       | BGP Prefix | CC | Registry | Allocated  | AS Name\n"
            ."13335   | 1.1.1.1  | 1.1.1.0/24 | US | ARIN     | 2010-07-14 | Cloudflare, Inc.\n";

        $parse = new ReflectionMethod(BgpToolsClient::class, 'parse');
        $parse->setAccessible(true);
        $out = $parse->invoke(new BgpToolsClient, '1.1.1.1', $raw);

        $this->assertSame('13335', $out['asn']);
        $this->assertSame('Cloudflare, Inc.', $out['name']);
        $this->assertSame('1.1.1.0/24', $out['prefix']);
        $this->assertSame('1.1.1.1', $out['ip']);
        $this->assertSame('US', $out['country']);
        $this->assertSame('ARIN', $out['registry']);
    }

    public function test_ignores_comment_banner_lines(): void
    {
        $raw = "# this is bgp.tools\n"
            ."AS    | IP      | AS Name\n"
            ."15169 | 8.8.8.8 | Google LLC\n";

        $parse = new ReflectionMethod(BgpToolsClient::class, 'parse');
        $parse->setAccessible(true);
        $out = $parse->invoke(new BgpToolsClient, '8.8.8.8', $raw);

        $this->assertSame('15169', $out['asn']);
        $this->assertSame('Google LLC', $out['name']);
    }

    public function test_normalises_ip_and_asn_inputs(): void
    {
        $normalise = new ReflectionMethod(BgpToolsClient::class, 'normalise');
        $normalise->setAccessible(true);

        $this->assertSame('1.1.1.1', $normalise->invoke(null, '1.1.1.1'));
        $this->assertSame('as13335', $normalise->invoke(null, 'AS13335'));
        $this->assertSame('as13335', $normalise->invoke(null, '13335'));
        $this->assertNull($normalise->invoke(null, 'not a thing'));
    }
}
