<?php

namespace Tests\Feature;

use App\Enums\DiscoveryStatus;
use App\Enums\PollMethod;
use App\Models\Credential;
use App\Models\DiscoveryCandidate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DiscoveryCandidateApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsUser();
    }

    public function test_lists_the_review_queue(): void
    {
        DiscoveryCandidate::factory()->count(3)->create();

        $this->getJson('/api/discovery-candidates')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_filters_by_status(): void
    {
        DiscoveryCandidate::factory()->create(['ip' => '1.1.1.1', 'status' => DiscoveryStatus::New]);
        DiscoveryCandidate::factory()->create(['ip' => '2.2.2.2', 'status' => DiscoveryStatus::Ignored]);

        $this->getJson('/api/discovery-candidates?status=new')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'new')
            ->assertJsonPath('data.0.ip', '1.1.1.1');
    }

    public function test_approve_creates_the_device_and_marks_the_candidate_approved(): void
    {
        Queue::fake();
        $cred = Credential::factory()->routeros()->create();
        $candidate = DiscoveryCandidate::factory()->create([
            'ip' => '10.0.0.5',
            'sysname' => 'RB-EDGE',
            'detected_method' => PollMethod::RouterOs,
            'matched_credential_id' => $cred->id,
            'status' => DiscoveryStatus::New,
        ]);

        $this->postJson("/api/discovery-candidates/{$candidate->id}/approve")
            ->assertCreated()
            ->assertJsonPath('data.mgmt_ip', '10.0.0.5')
            ->assertJsonPath('data.name', 'RB-EDGE')
            ->assertJsonPath('data.poll_method', 'routeros')
            ->assertJsonPath('data.credential_id', $cred->id);

        $this->assertDatabaseHas('devices', ['mgmt_ip' => '10.0.0.5', 'name' => 'RB-EDGE']);
        $this->assertSame(DiscoveryStatus::Approved, $candidate->fresh()->status);
    }

    public function test_approve_rejects_an_unidentified_candidate(): void
    {
        Queue::fake();
        $candidate = DiscoveryCandidate::factory()->create([
            'ip' => '10.0.0.6',
            'detected_method' => null,
            'matched_credential_id' => null,
            'status' => DiscoveryStatus::New,
        ]);

        $this->postJson("/api/discovery-candidates/{$candidate->id}/approve")
            ->assertStatus(422);

        $this->assertDatabaseCount('devices', 0);
        $this->assertSame(DiscoveryStatus::New, $candidate->fresh()->status);
    }

    public function test_ignore_marks_the_candidate_ignored(): void
    {
        $candidate = DiscoveryCandidate::factory()->create(['status' => DiscoveryStatus::New]);

        $this->postJson("/api/discovery-candidates/{$candidate->id}/ignore")
            ->assertOk()
            ->assertJsonPath('data.status', 'ignored');

        $this->assertSame(DiscoveryStatus::Ignored, $candidate->fresh()->status);
    }
}
