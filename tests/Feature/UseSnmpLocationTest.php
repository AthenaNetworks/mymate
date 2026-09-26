<?php

namespace Tests\Feature;

use App\Actions\Devices\CaptureDeviceFacts;
use App\Enums\PollMethod;
use App\Models\Credential;
use App\Models\Device;
use App\Models\User;
use App\Services\Snmp\SnmpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeSnmpClient;
use Tests\TestCase;

/**
 * GitHub #22: "use the SNMP location" hands a hand-placed device back to the coordinates its
 * own SNMP / RouterOS location advertises.
 */
class UseSnmpLocationTest extends TestCase
{
    use RefreshDatabase;

    private function captureLocation(Device $device, string $location): void
    {
        $oids = config('mymate.snmp.oids');
        $snmp = new FakeSnmpClient;
        $snmp->getsByCommunity['public-test'] = [
            $oids['sys_descr'] => 'RouterOS RB5009 7.14',
            $oids['sys_location'] => $location,
        ];
        $this->app->instance(SnmpClient::class, $snmp);

        app(CaptureDeviceFacts::class)($device);
    }

    private function snmpDevice(array $attrs = []): Device
    {
        $cred = Credential::factory()->create(['snmp_community' => 'public-test']);

        return Device::factory()->create(['poll_method' => PollMethod::Snmp, 'credential_id' => $cred->id] + $attrs);
    }

    public function test_capture_remembers_the_snmp_coordinates_even_under_a_manual_pin(): void
    {
        $device = $this->snmpDevice(['latitude' => 10.0, 'longitude' => 20.0, 'geo_source' => 'manual']);

        $this->captureLocation($device, '[-27.4698, 153.0251]');
        $device->refresh();

        $this->assertSame(10.0, $device->latitude); // the pin itself is still left alone
        $this->assertSame('manual', $device->geo_source);
        $this->assertEqualsWithDelta(-27.4698, $device->snmp_latitude, 0.0001);
        $this->assertEqualsWithDelta(153.0251, $device->snmp_longitude, 0.0001);
    }

    public function test_a_location_without_coordinates_clears_the_remembered_ones(): void
    {
        $device = $this->snmpDevice(['snmp_latitude' => -27.0, 'snmp_longitude' => 153.0]);

        $this->captureLocation($device, 'Some comms room, no coords');
        $device->refresh();

        $this->assertNull($device->snmp_latitude);
        $this->assertNull($device->snmp_longitude);
    }

    public function test_admin_moves_a_manual_pin_straight_to_the_known_snmp_location(): void
    {
        $this->actingAsUser();
        $device = Device::factory()->create([
            'latitude' => 10.0, 'longitude' => 20.0, 'geo_source' => 'manual',
            'snmp_latitude' => -27.4698, 'snmp_longitude' => 153.0251,
        ]);

        $this->postJson("/api/devices/{$device->id}/use-snmp-location")
            ->assertOk()
            ->assertJsonPath('meta.moved', true)
            ->assertJsonPath('data.geo_source', 'snmp');

        $device->refresh();
        $this->assertEqualsWithDelta(-27.4698, $device->latitude, 0.0001);
        $this->assertEqualsWithDelta(153.0251, $device->longitude, 0.0001);
        $this->assertSame('snmp', $device->geo_source);
    }

    public function test_without_known_coordinates_the_flag_is_dropped_and_the_next_capture_places_it(): void
    {
        $this->actingAsUser();
        $device = $this->snmpDevice(['latitude' => 10.0, 'longitude' => 20.0, 'geo_source' => 'manual']);

        $this->postJson("/api/devices/{$device->id}/use-snmp-location")
            ->assertOk()
            ->assertJsonPath('meta.moved', false)
            ->assertJsonPath('data.geo_source', null);

        // Pin stays put for now, but the next capture is free to move it.
        $this->assertSame(10.0, $device->fresh()->latitude);

        $this->captureLocation($device->fresh(), '[-27.4698, 153.0251]');
        $device->refresh();
        $this->assertEqualsWithDelta(-27.4698, $device->latitude, 0.0001);
        $this->assertSame('snmp', $device->geo_source);
    }

    public function test_a_viewer_cannot_use_it(): void
    {
        $viewer = User::factory()->create(['is_admin' => false]);
        $device = Device::factory()->create([
            'latitude' => 10.0, 'longitude' => 20.0, 'geo_source' => 'manual',
            'snmp_latitude' => -27.4698, 'snmp_longitude' => 153.0251,
        ]);

        $this->actingAs($viewer)->postJson("/api/devices/{$device->id}/use-snmp-location")->assertForbidden();

        $this->assertSame('manual', $device->fresh()->geo_source);
        $this->assertSame(10.0, $device->fresh()->latitude);
    }

    public function test_saving_the_editor_with_unchanged_coordinates_keeps_the_snmp_source(): void
    {
        $this->actingAsUser();
        $device = Device::factory()->create(['latitude' => -27.4698, 'longitude' => 153.0251, 'geo_source' => 'snmp']);

        // The edit forms send the coordinates back on every save.
        $this->patchJson("/api/devices/{$device->id}", ['name' => 'renamed', 'latitude' => -27.4698, 'longitude' => 153.0251])
            ->assertOk()
            ->assertJsonPath('data.geo_source', 'snmp');

        // A real move is still a manual pin.
        $this->patchJson("/api/devices/{$device->id}", ['latitude' => -27.5, 'longitude' => 153.0251])
            ->assertOk()
            ->assertJsonPath('data.geo_source', 'manual');
    }
}
