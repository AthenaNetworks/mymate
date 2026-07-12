<?php

namespace Tests\Unit;

use App\Services\Snmp\PhpSnmpClient;
use PHPUnit\Framework\TestCase;

class PhpSnmpClientTest extends TestCase
{
    public function test_strips_type_prefix_from_numeric_values(): void
    {
        $this->assertSame('1000', PhpSnmpClient::plain('Gauge32: 1000'));
        $this->assertSame('4679174290275', PhpSnmpClient::plain('Counter64: 4679174290275'));
        $this->assertSame('6', PhpSnmpClient::plain('INTEGER: 6'));
    }

    public function test_strips_prefix_and_quotes_from_strings(): void
    {
        $this->assertSame('ether1', PhpSnmpClient::plain('STRING: "ether1"'));
        $this->assertSame('cpe1.example.com', PhpSnmpClient::plain('STRING: "cpe1.example.com"'));
    }

    public function test_handles_hyphenated_type_and_inner_colons(): void
    {
        $this->assertSame('00 1B', PhpSnmpClient::plain('Hex-STRING: 00 1B'));
        $this->assertSame('a: b', PhpSnmpClient::plain('STRING: "a: b"'));
    }

    public function test_passes_through_already_plain_values(): void
    {
        // Builds that honour SNMP_VALUE_PLAIN return bare values - must be untouched.
        $this->assertSame('ether1', PhpSnmpClient::plain('ether1'));
        $this->assertSame('1000', PhpSnmpClient::plain('1000'));
    }
}
