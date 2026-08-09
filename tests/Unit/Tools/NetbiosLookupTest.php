<?php

namespace Tests\Unit\Tools;

use App\Services\Tools\NetbiosLookup;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class NetbiosLookupTest extends TestCase
{
    public function test_parses_a_node_status_response_into_name_and_mac(): void
    {
        // A hand-built NBNS node-status reply: header + echoed "*" name + one unique <00> name
        // ("MYPC") + a 6-byte adapter MAC.
        $header = pack('n6', 0x1337, 0x8500, 0, 1, 0, 0);
        $name = chr(0x20).str_repeat('A', 32).chr(0x00); // length-prefixed encoded name + terminator
        $fixed = pack('n', 0x0021).pack('n', 0x0001).pack('N', 0).pack('n', 25); // TYPE, CLASS, TTL, RDLENGTH
        $entry = str_pad('MYPC', 15, ' ').chr(0x00).pack('n', 0x0400); // name(15) + suffix<00> + unique flags
        $mac = "\x00\x11\x22\x33\x44\x55";
        $rdata = chr(1).$entry.$mac; // one name

        $response = $header.$name.$fixed.$rdata;

        $parse = new ReflectionMethod(NetbiosLookup::class, 'parse');
        $parse->setAccessible(true);
        $out = $parse->invoke(null, $response);

        $this->assertSame('MYPC', $out['name']);
        $this->assertNull($out['group']);
        $this->assertSame('00:11:22:33:44:55', $out['mac']);
    }

    public function test_picks_the_group_name_out_of_a_multi_name_response(): void
    {
        $header = pack('n6', 0x1337, 0x8500, 0, 1, 0, 0);
        $name = chr(0x20).str_repeat('A', 32).chr(0x00);
        $fixed = pack('n', 0x0021).pack('n', 0x0001).pack('N', 0).pack('n', 43);
        $unique = str_pad('BOX1', 15, ' ').chr(0x00).pack('n', 0x0400);       // workstation
        $groupN = str_pad('WORKGROUP', 15, ' ').chr(0x00).pack('n', 0x8400); // group bit set
        $rdata = chr(2).$unique.$groupN."\x0a\x0b\x0c\x0d\x0e\x0f";

        $response = $header.$name.$fixed.$rdata;

        $parse = new ReflectionMethod(NetbiosLookup::class, 'parse');
        $parse->setAccessible(true);
        $out = $parse->invoke(null, $response);

        $this->assertSame('BOX1', $out['name']);
        $this->assertSame('WORKGROUP', $out['group']);
        $this->assertSame('0a:0b:0c:0d:0e:0f', $out['mac']);
    }
}
