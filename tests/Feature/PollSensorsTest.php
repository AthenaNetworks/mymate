<?php

namespace Tests\Feature;

use App\Actions\Polling\PollSensors;
use App\Models\Credential;
use App\Models\Device;
use App\Models\Sensor;
use App\Services\Snmp\SnmpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeSnmpClient;
use Tests\TestCase;

class PollSensorsTest extends TestCase
{
    use RefreshDatabase;

    private function fakeSnmp(array $getsByCommunity): void
    {
        $snmp = new FakeSnmpClient;
        $snmp->getsByCommunity = $getsByCommunity;
        $this->app->instance(SnmpClient::class, $snmp);
    }

    public function test_polls_stores_the_current_reading_and_history_with_the_divisor_applied(): void
    {
        $this->fakeSnmp(['public' => ['.1.3.6.1.2.1.2.2.1.14.1' => '4200']]);
        $cred = Credential::factory()->create(['snmp_community' => 'public']);
        $device = Device::factory()->create(['credential_id' => $cred->id]);
        $sensor = Sensor::factory()->create(['oid' => '.1.3.6.1.2.1.2.2.1.14.1', 'divisor' => 100]);

        app(PollSensors::class)([$device->id]);

        $this->assertDatabaseHas('sensor_readings', ['sensor_id' => $sensor->id, 'device_id' => $device->id, 'value' => 42]);
        $this->assertDatabaseHas('sensor_samples', ['sensor_id' => $sensor->id, 'device_id' => $device->id, 'value' => 42]);
    }

    public function test_walk_mode_sums_a_table(): void
    {
        $snmp = new \Tests\Support\FakeSnmpClient;
        $snmp->walks['.1.3.6.1.2.1.2.2.1.14'] = [1 => '10', 2 => '25', 3 => '5']; // 3 ports of in-errors
        $this->app->instance(SnmpClient::class, $snmp);
        $cred = Credential::factory()->create(['snmp_community' => 'public']);
        $device = Device::factory()->create(['credential_id' => $cred->id]);
        $sensor = Sensor::factory()->create(['oid' => '.1.3.6.1.2.1.2.2.1.14', 'mode' => 'walk', 'agg' => 'sum', 'divisor' => 1]);

        app(PollSensors::class)([$device->id]);

        $this->assertDatabaseHas('sensor_readings', ['sensor_id' => $sensor->id, 'device_id' => $device->id, 'value' => 40]);
    }

    public function test_walk_mode_counts_rows(): void
    {
        $snmp = new \Tests\Support\FakeSnmpClient;
        // e.g. an OSPF neighbour table - count how many entries there are.
        $snmp->walks['.1.3.6.1.2.1.14.10.1.6'] = ['a' => '8', 'b' => '8', 'c' => '8'];
        $this->app->instance(SnmpClient::class, $snmp);
        $cred = Credential::factory()->create(['snmp_community' => 'public']);
        $device = Device::factory()->create(['credential_id' => $cred->id]);
        $sensor = Sensor::factory()->create(['oid' => '.1.3.6.1.2.1.14.10.1.6', 'mode' => 'walk', 'agg' => 'count', 'divisor' => 1]);

        app(PollSensors::class)([$device->id]);

        $this->assertDatabaseHas('sensor_readings', ['sensor_id' => $sensor->id, 'device_id' => $device->id, 'value' => 3]);
    }

    public function test_only_polls_devices_in_the_sensor_scope(): void
    {
        $this->fakeSnmp(['public' => ['.1.3.6' => '5']]);
        $cred = Credential::factory()->create(['snmp_community' => 'public']);
        $inScope = Device::factory()->create(['credential_id' => $cred->id]);
        $outScope = Device::factory()->create(['credential_id' => $cred->id]);
        Sensor::factory()->create(['oid' => '.1.3.6', 'scope' => ['type' => 'devices', 'device_ids' => [$inScope->id]]]);

        app(PollSensors::class)([$inScope->id, $outScope->id]);

        $this->assertDatabaseHas('sensor_readings', ['device_id' => $inScope->id]);
        $this->assertDatabaseMissing('sensor_readings', ['device_id' => $outScope->id]);
    }

    public function test_skips_devices_without_an_snmp_community(): void
    {
        $this->fakeSnmp(['public' => ['.1.3.6' => '5']]);
        $device = Device::factory()->create(['credential_id' => null]); // no SNMP credential
        Sensor::factory()->create(['oid' => '.1.3.6']);

        app(PollSensors::class)([$device->id]);

        $this->assertDatabaseCount('sensor_readings', 0);
    }
}
