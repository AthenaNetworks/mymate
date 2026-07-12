<?php

namespace Tests\Feature;

use App\Actions\Outages\RecordOutage;
use App\Models\Device;
use App\Models\Outage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutageApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_outage_is_idempotent_and_stamps_duration(): void
    {
        $device = Device::factory()->create();
        $rec = app(RecordOutage::class);

        $this->travelTo(now()->startOfMinute());
        $rec->open($device);
        $rec->open($device); // again - still one open outage
        $this->assertSame(1, Outage::where('device_id', $device->id)->whereNull('ended_at')->count());

        $this->travel(90)->seconds();
        $rec->close($device);

        $outage = Outage::where('device_id', $device->id)->firstOrFail();
        $this->assertNotNull($outage->ended_at);
        $this->assertSame(90, $outage->duration_s);
        $this->travelBack();
    }

    public function test_lists_outages_newest_first_with_device_name(): void
    {
        $this->actingAsUser();
        $device = Device::factory()->create(['name' => 'CPE-X']);
        Outage::factory()->for($device)->closed()->create();
        Outage::factory()->for($device)->create(); // open

        $this->getJson('/api/outages')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.device_name', 'CPE-X');
    }

    public function test_filters_by_state_and_device(): void
    {
        $this->actingAsUser();
        $a = Device::factory()->create();
        $b = Device::factory()->create();
        Outage::factory()->for($a)->create();           // open
        Outage::factory()->for($a)->closed()->create(); // closed
        Outage::factory()->for($b)->create();           // open, other device

        $this->getJson('/api/outages?state=open')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/outages?state=closed')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/outages?device_id={$a->id}")->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_requires_authentication(): void
    {
        Outage::factory()->create();

        $this->getJson('/api/outages')->assertUnauthorized();
    }
}
