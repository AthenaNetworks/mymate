<?php

namespace Tests\Feature;

use App\Enums\DiscoveryStatus;
use App\Enums\PollMethod;
use App\Models\Credential;
use App\Models\Device;
use App\Models\DiscoveryCandidate;
use App\Models\NetworkInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneUnidentifiedDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_prunes_only_unidentified_unreviewed_candidates(): void
    {
        $cred = Credential::factory()->create();
        $unidentified = DiscoveryCandidate::factory()->create([
            'ip' => '10.0.0.1', 'detected_method' => null, 'status' => DiscoveryStatus::New,
        ]);
        $identified = DiscoveryCandidate::factory()->create([
            'ip' => '10.0.0.2', 'detected_method' => PollMethod::Snmp, 'matched_credential_id' => $cred->id, 'status' => DiscoveryStatus::New,
        ]);
        $approvedNull = DiscoveryCandidate::factory()->create([
            'ip' => '10.0.0.3', 'detected_method' => null, 'status' => DiscoveryStatus::Approved,
        ]);

        $this->artisan('mymate:discovery:prune')->assertSuccessful();

        $this->assertDatabaseMissing('discovery_candidates', ['id' => $unidentified->id]);
        $this->assertDatabaseHas('discovery_candidates', ['id' => $identified->id]);
        $this->assertDatabaseHas('discovery_candidates', ['id' => $approvedNull->id]); // approved untouched
    }

    public function test_devices_flag_removes_only_orphan_null_credential_snmp_devices(): void
    {
        $cred = Credential::factory()->create(['type' => 'snmp']);

        $orphan = Device::factory()->create(['poll_method' => PollMethod::Snmp, 'credential_id' => null]); // no ifaces
        $withCred = Device::factory()->create(['poll_method' => PollMethod::Snmp, 'credential_id' => $cred->id]);
        $withIfaces = Device::factory()->create(['poll_method' => PollMethod::Snmp, 'credential_id' => null]);
        NetworkInterface::factory()->create(['device_id' => $withIfaces->id]);
        $routerOs = Device::factory()->create(['poll_method' => PollMethod::RouterOs, 'credential_id' => null]);

        // Without --devices, devices are untouched.
        $this->artisan('mymate:discovery:prune')->assertSuccessful();
        $this->assertDatabaseHas('devices', ['id' => $orphan->id]);

        // With --devices, only the orphan null-credential SNMP device with no interfaces goes.
        $this->artisan('mymate:discovery:prune', ['--devices' => true])->assertSuccessful();

        $this->assertDatabaseMissing('devices', ['id' => $orphan->id]);
        $this->assertDatabaseHas('devices', ['id' => $withCred->id]);     // has a credential
        $this->assertDatabaseHas('devices', ['id' => $withIfaces->id]);   // has interfaces
        $this->assertDatabaseHas('devices', ['id' => $routerOs->id]);     // not SNMP
    }
}
