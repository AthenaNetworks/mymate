<?php

namespace Tests\Unit;

use App\Services\Neighbors\NeighborTables as T;
use App\Services\Neighbors\Octets;
use PHPUnit\Framework\TestCase;

/** Parsing the raw LLDP / CDP / RouterOS neighbour tables (GitHub #85). */
class NeighborTablesTest extends TestCase
{
    public function test_lldp_rows_carry_local_port_names_remote_chassis_ports_and_management_address(): void
    {
        $tables = new T(walks: [
            // Comware: local port number 29 is ifIndex 31, so the name is what matters.
            T::LLDP_LOC_PORT_ID => ['29' => 'Ten-GigabitEthernet1/0/29'],
            T::LLDP_LOC_PORT_DESC => ['29' => 'Ten-GigabitEthernet1/0/29 Interface'],
            T::LLDP_REM_CHASSIS_ID => ['0.29.1' => "\x00\x11\x22\x33\x44\x55", '0.30.2' => 'hex:aabbccddeeff'],
            T::LLDP_REM_PORT_ID_SUBTYPE => ['0.29.1' => '5', '0.30.2' => '3'],
            T::LLDP_REM_PORT_ID => ['0.29.1' => 'sfp-sfpplus1', '0.30.2' => "\xaa\xbb\xcc\xdd\xee\x01"],
            T::LLDP_REM_PORT_DESC => ['0.29.1' => 'uplink', '0.30.2' => 'bond0'],
            T::LLDP_REM_SYS_NAME => ['0.29.1' => 'core1.example.net', '0.30.2' => 'ap-lobby'],
            T::LLDP_REM_MAN_ADDR => ['0.29.1.1.4.10.13.1.5' => '2'],
        ], scalars: [T::LLDP_LOC_CHASSIS_ID => 'hex:001122334466']);

        [$core, $ap] = $tables->parse();

        $this->assertSame('lldp', $core->protocol);
        $this->assertSame(['Ten-GigabitEthernet1/0/29', 'Ten-GigabitEthernet1/0/29 Interface'], $core->localPorts);
        $this->assertSame(29, $core->localPortNum);
        $this->assertSame('00:11:22:33:44:55', $core->remoteChassis);
        $this->assertSame('core1.example.net', $core->remoteName);
        $this->assertSame(['sfp-sfpplus1', 'uplink'], $core->remotePorts);
        $this->assertSame('10.13.1.5', $core->remoteIp);

        // A MAC port id (subtype 3) is no use as a name - only the description is offered.
        $this->assertSame('aa:bb:cc:dd:ee:ff', $ap->remoteChassis);
        $this->assertSame(['bond0'], $ap->remotePorts);
        $this->assertSame([], $ap->localPorts);
        $this->assertNull($ap->remoteIp);

        $this->assertSame('00:11:22:33:44:66', $tables->localChassisId());
    }

    public function test_cdp_rows_give_the_local_ifindex_and_strip_the_serial(): void
    {
        $tables = new T(walks: [
            T::CDP_DEVICE_ID => ['10102.1' => 'SW1(FOC1234X0YZ)'],
            T::CDP_DEVICE_PORT => ['10102.1' => 'GigabitEthernet1/0/48'],
            T::CDP_ADDRESS_TYPE => ['10102.1' => '1'],
            T::CDP_ADDRESS => ['10102.1' => "\x0a\x00\x00\x02"],
        ]);

        [$sw] = $tables->parse();

        $this->assertSame('cdp', $sw->protocol);
        $this->assertSame(10102, $sw->localIfIndex);
        $this->assertSame('SW1', $sw->remoteName);
        $this->assertSame(['GigabitEthernet1/0/48'], $sw->remotePorts);
        $this->assertSame('10.0.0.2', $sw->remoteIp);
    }

    public function test_routeros_neighbors_use_the_physical_port_and_say_how_they_were_heard(): void
    {
        $tables = new T(routeros: [
            ['interface' => 'ether2,bridge', 'identity' => 'dist1', 'address' => '10.1.1.2', 'mac-address' => '48:A9:8A:00:00:01', 'interface-name' => 'ether1', 'discovered-by' => 'lldp,mndp'],
            ['interface' => 'sfp1', 'identity' => 'cisco-sw', 'address' => 'fe80::1%sfp1', 'interface-name' => 'Gi0/1', 'discovered-by' => 'cdp'],
        ]);

        [$mt, $cisco] = $tables->parse();

        $this->assertSame('mndp', $mt->protocol);
        $this->assertSame(['ether2'], $mt->localPorts);
        $this->assertSame('48:a9:8a:00:00:01', $mt->remoteChassis);
        $this->assertSame('10.1.1.2', $mt->remoteIp);
        $this->assertSame(['ether1'], $mt->remotePorts);

        $this->assertSame('cdp', $cisco->protocol);
        $this->assertNull($cisco->remoteIp);
    }

    public function test_an_agent_payload_is_read_tolerantly(): void
    {
        $tables = T::fromArray([
            'walks' => [T::LLDP_REM_SYS_NAME => ['0.1.1' => 'peer'], '1.3.6.1.2.1.1.5' => ['0' => 'not ours'], T::LLDP_REM_PORT_ID => 'junk'],
            'scalars' => [T::LLDP_LOC_CHASSIS_ID => 'hex:0a0b0c0d0e0f', 'other' => 'x'],
            'routeros' => ['nope', ['identity' => 'r1']],
        ]);

        $this->assertSame([T::LLDP_REM_SYS_NAME], array_keys($tables->walks));
        $this->assertCount(1, $tables->routeros);
        $this->assertSame('0a:0b:0c:0d:0e:0f', $tables->localChassisId());
        $this->assertCount(2, $tables->parse());
        $this->assertCount(0, T::fromArray([])->parse());
    }

    public function test_octets_normalise_every_form_a_chassis_id_arrives_in(): void
    {
        $this->assertSame('00:11:22:33:44:55', Octets::id("\x00\x11\x22\x33\x44\x55"));
        $this->assertSame('00:11:22:33:44:55', Octets::id('hex:001122334455'));
        $this->assertSame('00:11:22:33:44:55', Octets::id('00 11 22 33 44 55'));
        $this->assertSame('00:11:22:33:44:55', Octets::id('00-11-22-33-44-55'));
        $this->assertSame('sw-core', Octets::id('SW-Core'));
        $this->assertNull(Octets::id(''));
        $this->assertNull(Octets::text("\x00\x01"));
        $this->assertNull(Octets::text('hex:00'));
    }
}
