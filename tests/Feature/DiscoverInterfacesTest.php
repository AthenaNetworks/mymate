<?php

namespace Tests\Feature;

use App\Actions\Polling\DiscoverInterfaces;
use App\Enums\PollMethod;
use App\Models\Credential;
use App\Models\Device;
use App\Models\NetworkInterface;
use App\Services\Snmp\SnmpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeSnmpClient;
use Tests\TestCase;

class DiscoverInterfacesTest extends TestCase
{
    use RefreshDatabase;

    private function snmpDevice(): Device
    {
        $credential = Credential::factory()->create(['snmp_community' => 'public-test']);

        return Device::factory()->create([
            'poll_method' => PollMethod::Snmp,
            'credential_id' => $credential->id,
        ]);
    }

    /** Bind a fake SnmpClient so the real factory + SnmpThroughputDriver run end-to-end. */
    private function fakeSnmp(array $names, array $speeds, array $aliases = []): void
    {
        $oids = config('mymate.snmp.oids');
        $snmp = new FakeSnmpClient;
        $snmp->walks[$oids['if_name']] = $names;
        $snmp->walks[$oids['if_high_speed']] = $speeds;
        $snmp->walks[$oids['if_alias']] = $aliases;
        $this->app->instance(SnmpClient::class, $snmp);
    }

    public function test_upserts_interfaces_for_the_device(): void
    {
        $device = $this->snmpDevice();
        $this->fakeSnmp([1 => 'ether1', 2 => 'ether2'], [1 => '1000', 2 => '1000']);

        $count = app(DiscoverInterfaces::class)($device);

        $this->assertSame(2, $count);
        $this->assertDatabaseHas('interfaces', [
            'device_id' => $device->id, 'if_index' => 1, 'name' => 'ether1', 'speed_mbps' => 1000,
        ]);
        $this->assertSame(2, NetworkInterface::where('device_id', $device->id)->count());
    }

    public function test_persists_the_interface_description_from_ifalias(): void
    {
        $device = $this->snmpDevice();
        $this->fakeSnmp([1 => 'ether1'], [1 => '1000'], [1 => 'Uplink to BDR1']);

        app(DiscoverInterfaces::class)($device);

        $this->assertDatabaseHas('interfaces', [
            'device_id' => $device->id, 'if_index' => 1, 'description' => 'Uplink to BDR1',
        ]);
    }

    public function test_rediscovery_refreshes_speed_from_snmp(): void
    {
        // Interface speed is read-only from SNMP: a stale value is
        // always overwritten on re-discovery; bandwidth overrides live on the link now.
        $device = $this->snmpDevice();

        $this->fakeSnmp([1 => 'ether1'], [1 => '1000']);
        app(DiscoverInterfaces::class)($device);

        // Something stale the prior poll left behind.
        NetworkInterface::where('device_id', $device->id)->where('if_index', 1)
            ->update(['speed_mbps' => 300]);

        // Re-discovery reports the negotiated 1 G + renames the port.
        $this->fakeSnmp([1 => 'ether1-radio'], [1 => '1000']);
        app(DiscoverInterfaces::class)($device);

        $iface = NetworkInterface::where('device_id', $device->id)->where('if_index', 1)->first();
        $this->assertSame(1000, $iface->speed_mbps);          // SNMP value always wins
        $this->assertSame('ether1-radio', $iface->name);      // name still refreshed
    }

    public function test_records_discovery_error_when_the_driver_fails(): void
    {
        // SNMP walk times out (filtered/unreachable) -> surface it instead of a silent 0 (#9).
        $device = $this->snmpDevice();
        $snmp = new FakeSnmpClient;
        $snmp->throwOnWalk = true;
        $this->app->instance(SnmpClient::class, $snmp);

        $count = app(DiscoverInterfaces::class)($device);

        $this->assertSame(0, $count);
        $device->refresh();
        $this->assertStringContainsString('SnmpClientException', $device->discovery_error);
        $this->assertNotNull($device->discovered_at);
        $this->assertSame(0, NetworkInterface::where('device_id', $device->id)->count());
    }

    public function test_records_an_error_when_no_interfaces_are_returned(): void
    {
        $device = $this->snmpDevice();
        $this->fakeSnmp([], []); // reachable but the walk yields nothing

        $count = app(DiscoverInterfaces::class)($device);

        $this->assertSame(0, $count);
        $device->refresh();
        $this->assertStringContainsString('No interfaces', $device->discovery_error);
        $this->assertNotNull($device->discovered_at);
    }

    public function test_clears_a_previous_discovery_error_on_success(): void
    {
        $device = $this->snmpDevice();
        $device->forceFill(['discovery_error' => 'SnmpClientException: old failure'])->save();

        $this->fakeSnmp([1 => 'ether1'], [1 => '1000']);
        app(DiscoverInterfaces::class)($device);

        $device->refresh();
        $this->assertNull($device->discovery_error);
        $this->assertNotNull($device->discovered_at);
    }

    public function test_rerun_updates_in_place_without_duplicating(): void
    {
        $device = $this->snmpDevice();

        $this->fakeSnmp([1 => 'ether1'], [1 => '1000']);
        app(DiscoverInterfaces::class)($device);

        // Interface renamed + relinked on the device; same ifIndex.
        $this->fakeSnmp([1 => 'ether1-uplink'], [1 => '10000']);
        app(DiscoverInterfaces::class)($device);

        $this->assertSame(1, NetworkInterface::where('device_id', $device->id)->count());
        $this->assertDatabaseHas('interfaces', [
            'device_id' => $device->id, 'if_index' => 1, 'name' => 'ether1-uplink', 'speed_mbps' => 10000,
        ]);
    }
}
