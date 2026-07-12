<?php

namespace Tests\Unit;

use App\Enums\PollMethod;
use App\Models\Device;
use App\Support\RustedDrivers;
use PHPUnit\Framework\TestCase;

/** Vendor/poll-method -> Rusted driver suggestion. No DB - plain models. */
class RustedDriversTest extends TestCase
{
    private function device(array $attrs): Device
    {
        $device = new Device;
        $device->forceFill($attrs);

        return $device;
    }

    public function test_routeros_poll_method_maps_to_mikrotik_driver(): void
    {
        $this->assertSame('mikrotik_routeros', RustedDrivers::suggestFor($this->device([
            'poll_method' => PollMethod::RouterOs,
        ])));
    }

    public function test_vendor_keywords_map_to_drivers(): void
    {
        $this->assertSame('mikrotik_routeros', RustedDrivers::suggestFor($this->device(['poll_method' => PollMethod::Snmp, 'vendor' => 'MikroTik'])));
        $this->assertSame('juniper_junos', RustedDrivers::suggestFor($this->device(['poll_method' => PollMethod::Snmp, 'vendor' => 'Juniper Networks'])));
        $this->assertSame('cisco_nxos', RustedDrivers::suggestFor($this->device(['poll_method' => PollMethod::Snmp, 'vendor' => 'Cisco Nexus'])));
        $this->assertSame('cisco_ios', RustedDrivers::suggestFor($this->device(['poll_method' => PollMethod::Snmp, 'vendor' => 'Cisco Systems'])));
    }

    public function test_unknown_vendor_returns_null_not_generic(): void
    {
        $this->assertNull(RustedDrivers::suggestFor($this->device(['poll_method' => PollMethod::Snmp, 'vendor' => 'Acme Widgets'])));
        $this->assertNull(RustedDrivers::suggestFor($this->device(['poll_method' => PollMethod::None, 'vendor' => null])));
    }

    public function test_all_suggested_drivers_are_in_the_allowed_list(): void
    {
        foreach (['mikrotik_routeros', 'juniper_junos', 'cisco_nxos', 'cisco_ios'] as $driver) {
            $this->assertContains($driver, RustedDrivers::ALL);
        }
    }
}
