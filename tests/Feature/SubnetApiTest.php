<?php

namespace Tests\Feature;

use App\Jobs\ScanSubnetJob;
use App\Models\Subnet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SubnetApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsUser();
    }

    public function test_lists_subnets(): void
    {
        Subnet::factory()->count(2)->create();

        $this->getJson('/api/subnets')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_creates_a_subnet_with_a_default_scan_interval(): void
    {
        $this->postJson('/api/subnets', ['cidr' => '10.80.111.0/24', 'label' => 'Lab'])
            ->assertCreated()
            ->assertJsonPath('data.cidr', '10.80.111.0/24')
            ->assertJsonPath('data.label', 'Lab')
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.scan_interval_s', (int) config('mymate.discovery.default_scan_interval_s'));

        $this->assertDatabaseHas('subnets', ['cidr' => '10.80.111.0/24']);
    }

    public function test_rejects_an_invalid_cidr(): void
    {
        $this->postJson('/api/subnets', ['cidr' => 'not-a-cidr'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cidr']);

        $this->postJson('/api/subnets', ['cidr' => '10.0.0.0/33'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cidr']);
    }

    public function test_rejects_a_duplicate_cidr(): void
    {
        Subnet::factory()->create(['cidr' => '10.0.0.0/24']);

        $this->postJson('/api/subnets', ['cidr' => '10.0.0.0/24'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cidr']);
    }

    public function test_updates_a_subnet(): void
    {
        $subnet = Subnet::factory()->create(['enabled' => true, 'scan_interval_s' => 3600]);

        $this->patchJson("/api/subnets/{$subnet->id}", ['enabled' => false, 'scan_interval_s' => 60])
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.scan_interval_s', 60);
    }

    public function test_deletes_a_subnet(): void
    {
        $subnet = Subnet::factory()->create();

        $this->deleteJson("/api/subnets/{$subnet->id}")->assertNoContent();
        $this->assertDatabaseMissing('subnets', ['id' => $subnet->id]);
    }

    public function test_scan_dispatches_a_scan_job(): void
    {
        Queue::fake();
        $subnet = Subnet::factory()->create();

        $this->postJson("/api/subnets/{$subnet->id}/scan")
            ->assertAccepted()
            ->assertJsonPath('data.id', $subnet->id);

        Queue::assertPushed(ScanSubnetJob::class, fn ($job) => $job->subnetId === $subnet->id);
    }

    public function test_scanning_an_agent_subnet_marks_it_due_instead_of_scanning_centrally(): void
    {
        Queue::fake();
        $agent = \App\Models\Agent::factory()->create();
        $subnet = Subnet::factory()->create(['agent_id' => $agent->id, 'last_scanned_at' => now()]);

        $this->postJson("/api/subnets/{$subnet->id}/scan")
            ->assertAccepted()
            ->assertJsonPath('data.id', $subnet->id);

        // No central sweep - the agent picks it up because it's now due (last_scanned_at cleared).
        Queue::assertNothingPushed();
        $this->assertNull($subnet->fresh()->last_scanned_at);
    }

    /**  hardening - reserved ranges have zero legitimate use as a scan target. */
    public function test_rejects_a_reserved_range_cidr(): void
    {
        $this->postJson('/api/subnets', ['cidr' => '127.0.0.0/8'])
            ->assertStatus(422)->assertJsonValidationErrors(['cidr']);

        $this->postJson('/api/subnets', ['cidr' => '169.254.0.0/16'])
            ->assertStatus(422)->assertJsonValidationErrors(['cidr']);
    }

    /** A single registration can't be broader than a /8 (blast-radius cap, not a scope block). */
    public function test_rejects_a_cidr_broader_than_a_slash_8(): void
    {
        $this->postJson('/api/subnets', ['cidr' => '1.0.0.0/7'])
            ->assertStatus(422)->assertJsonValidationErrors(['cidr']);
    }

    /** Public ranges stay allowed - MSPs/ISPs monitor their own public-IP infrastructure too. */
    public function test_still_allows_a_public_slash_8(): void
    {
        $this->postJson('/api/subnets', ['cidr' => '8.0.0.0/8'])->assertCreated();
    }
}
