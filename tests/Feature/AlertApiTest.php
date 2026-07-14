<?php

namespace Tests\Feature;

use App\Actions\Alerts\SendAlert;
use App\Models\AlertEvent;
use App\Models\AlertPolicy;
use App\Models\AlertTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AlertApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_policy_crud_with_transport_sync(): void
    {
        $this->actingAsUser();
        $transport = AlertTransport::factory()->create();

        $res = $this->postJson('/api/alert-policies', [
            'name' => 'Down alerts', 'condition' => 'device_down', 'transport_ids' => [$transport->id],
        ])->assertCreated()->assertJsonPath('data.condition', 'device_down');
        $id = $res->json('data.id');
        $this->assertEqualsCanonicalizing([$transport->id], $res->json('data.transport_ids'));

        $this->putJson("/api/alert-policies/{$id}", ['enabled' => false, 'transport_ids' => []])
            ->assertOk()->assertJsonPath('data.enabled', false);
        $this->assertSame(0, AlertPolicy::find($id)->transports()->count());

        $this->deleteJson("/api/alert-policies/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('alert_policies', ['id' => $id]);
    }

    public function test_policy_persists_targeting_scope(): void
    {
        $this->actingAsUser();

        $res = $this->postJson('/api/alert-policies', [
            'name' => 'Router downs', 'condition' => 'device_down',
            'scope' => ['type' => 'device_type', 'device_type' => 'router'],
        ])->assertCreated()
            ->assertJsonPath('data.scope.type', 'device_type')
            ->assertJsonPath('data.scope.device_type', 'router');
        $id = $res->json('data.id');

        // Revert to fleet-wide.
        $this->putJson("/api/alert-policies/{$id}", ['scope' => ['type' => 'all']])
            ->assertOk()->assertJsonPath('data.scope.type', 'all');
    }

    public function test_policy_scope_rejects_an_unknown_device_type(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/alert-policies', [
            'name' => 'Bad', 'condition' => 'device_down',
            'scope' => ['type' => 'device_type', 'device_type' => 'toaster'],
        ])->assertStatus(422)->assertJsonValidationErrors('scope.device_type');
    }

    public function test_high_metric_policy_persists_its_metric_and_threshold(): void
    {
        $this->actingAsUser();

        $res = $this->postJson('/api/alert-policies', [
            'name' => 'Hot routers', 'condition' => 'high_metric',
            'params' => ['metric' => 'temp', 'threshold' => 75],
        ])->assertCreated()
            ->assertJsonPath('data.condition', 'high_metric')
            ->assertJsonPath('data.params.metric', 'temp');

        $this->assertSame(75, (int) $res->json('data.params.threshold'));
    }

    public function test_backup_failed_policy_is_accepted(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/alert-policies', [
            'name' => 'Backup failures', 'condition' => 'backup_failed',
        ])->assertCreated()->assertJsonPath('data.condition', 'backup_failed');
    }

    public function test_high_metric_policy_rejects_an_unknown_metric(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/alert-policies', [
            'name' => 'Bad metric', 'condition' => 'high_metric',
            'params' => ['metric' => 'voltage'],
        ])->assertStatus(422)->assertJsonValidationErrors('params.metric');
    }

    public function test_transport_create_never_leaks_or_stores_plaintext_config(): void
    {
        $this->actingAsUser();

        $res = $this->postJson('/api/alert-transports', [
            'name' => 'Slack NOC', 'type' => 'slack', 'webhook_url' => 'https://hooks.slack.com/services/SECRET-XYZ',
        ])->assertCreated();

        $this->assertStringNotContainsString('SECRET-XYZ', json_encode($res->json()));
        $row = DB::table('alert_transports')->latest('id')->first();
        $this->assertStringNotContainsString('hooks.slack.com', (string) $row->config); // encrypted at rest
    }

    public function test_transport_validates_config_by_type(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/alert-transports', ['name' => 'E', 'type' => 'email'])
            ->assertStatus(422)->assertJsonValidationErrors(['email']);
        $this->postJson('/api/alert-transports', ['name' => 'S', 'type' => 'slack'])
            ->assertStatus(422)->assertJsonValidationErrors(['webhook_url']);
        // Messenger is a custom webhook -> also requires webhook_url.
        $this->postJson('/api/alert-transports', ['name' => 'M', 'type' => 'messenger'])
            ->assertStatus(422)->assertJsonValidationErrors(['webhook_url']);
    }

    public function test_messenger_transport_posts_the_text_payload(): void
    {
        Http::fake();
        $this->actingAsUser();

        $res = $this->postJson('/api/alert-transports', [
            'name' => 'My App', 'type' => 'messenger', 'webhook_url' => 'http://10.121.15.196:8000/api/hooks/abc',
        ])->assertCreated()->assertJsonPath('data.type', 'messenger');

        $this->postJson("/api/alert-transports/{$res->json('data.id')}/test")->assertOk()->assertJsonPath('ok', true);
        // Delivered as a {"text": ...} POST to the configured URL (same shape as Slack/Teams).
        Http::assertSent(fn ($request) => $request->url() === 'http://10.121.15.196:8000/api/hooks/abc'
            && $request['text'] !== null);
    }

    public function test_test_send_posts_through_the_transport(): void
    {
        Http::fake();
        $this->actingAsUser();
        $transport = AlertTransport::factory()->create();

        $this->postJson("/api/alert-transports/{$transport->id}/test")->assertOk()->assertJsonPath('ok', true);
        Http::assertSentCount(1);
    }

    public function test_lists_recent_events(): void
    {
        $this->actingAsUser();
        $policy = AlertPolicy::factory()->create();
        AlertEvent::create(['alert_policy_id' => $policy->id, 'dedupe_key' => 'k', 'status' => 'firing', 'message' => 'Device X is down.', 'fired_at' => now()]);

        $this->getJson('/api/alert-events')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.message', 'Device X is down.');
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/alert-policies')->assertUnauthorized();
    }

    /**  SSRF hardening - save-time rejection of loopback/link-local/reserved targets. */
    public function test_rejects_a_webhook_url_pointing_at_a_loopback_or_link_local_address(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/alert-transports', [
            'name' => 'Bad', 'type' => 'slack', 'webhook_url' => 'http://127.0.0.1/hook',
        ])->assertStatus(422)->assertJsonValidationErrors(['webhook_url']);

        $this->postJson('/api/alert-transports', [
            'name' => 'Metadata', 'type' => 'messenger', 'webhook_url' => 'http://169.254.169.254/latest/meta-data/',
        ])->assertStatus(422)->assertJsonValidationErrors(['webhook_url']);
    }

    /** A private-network webhook must still be allowed. */
    public function test_still_allows_a_private_network_webhook(): void
    {
        Http::fake();
        $this->actingAsUser();

        $this->postJson('/api/alert-transports', [
            'name' => 'Internal app', 'type' => 'messenger', 'webhook_url' => 'http://10.5.5.5:8000/hook',
        ])->assertCreated();
    }

    /**  SSRF hardening - send-time re-check even if an unsafe URL somehow made it into storage. */
    public function test_send_alert_refuses_to_deliver_to_an_unsafe_webhook(): void
    {
        Http::fake();
        $transport = AlertTransport::factory()->create([
            'config' => ['webhook_url' => 'http://127.0.0.1/hook'],
        ]);

        $threw = false;
        try {
            app(SendAlert::class)->deliver($transport, 'test message');
        } catch (\RuntimeException) {
            $threw = true;
        }

        $this->assertTrue($threw, 'expected deliver() to refuse an unsafe webhook target');
        Http::assertNothingSent();
    }
}
