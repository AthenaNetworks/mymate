<?php

namespace Tests\Feature;

use App\Actions\Alerts\NocgenieUnavailable;
use App\Actions\Alerts\SendAlert;
use App\Jobs\DeliverNocgenieAlertJob;
use App\Models\AlertEvent;
use App\Models\AlertPolicy;
use App\Models\AlertTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DeliverNocgenieAlertJobTest extends TestCase
{
    use RefreshDatabase;

    private AlertTransport $transport;

    private AlertEvent $event;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transport = AlertTransport::factory()->create(['type' => 'nocgenie', 'config' => ['nocgenie_token' => 'T']]);
        $policy = AlertPolicy::factory()->create();
        $policy->transports()->attach($this->transport->id);
        $this->event = AlertEvent::create([
            'alert_policy_id' => $policy->id, 'dedupe_key' => 'device:1', 'status' => 'firing',
            'message' => 'Device X is down.', 'delivered' => false, 'fired_at' => now(),
        ]);
    }

    private function job(string $kind): DeliverNocgenieAlertJob
    {
        return (new DeliverNocgenieAlertJob($this->transport->id, $this->event->id, 'msg', ['event' => $kind]))->withFakeQueueInteractions();
    }

    public function test_sending_an_alert_queues_nocgenie_instead_of_posting_inline(): void
    {
        Queue::fake();
        Http::fake();
        $policy = $this->event->policy;

        app(SendAlert::class)->send($policy, $this->event);

        Queue::assertPushedOn('poll', DeliverNocgenieAlertJob::class, fn ($job) => $job->context['event'] === 'alert.firing');
        Http::assertNothingSent();
        $this->assertFalse($this->event->fresh()->delivered); // the job marks it when it lands
    }

    public function test_a_landed_firing_marks_the_event_delivered(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Accepted.'], 202)]);

        $this->job('alert.firing')->handle(app(SendAlert::class));

        $this->assertTrue($this->event->fresh()->delivered);
    }

    public function test_a_server_error_is_thrown_so_the_backoff_retries_it(): void
    {
        Http::fake(['*' => Http::response([], 503)]);

        $this->expectException(NocgenieUnavailable::class);
        $this->job('alert.firing')->handle(app(SendAlert::class));
    }

    public function test_the_backoff_runs_for_about_twenty_minutes(): void
    {
        $job = $this->job('alert.firing');

        $this->assertSame([10, 30, 120, 300, 900], $job->backoff());
        $this->assertEqualsWithDelta(now()->addMinutes(25)->getTimestamp(), $job->retryUntil()->getTimestamp(), 2);
    }

    public function test_a_rate_limit_waits_as_long_as_retry_after_says(): void
    {
        Http::fake(['*' => Http::response([], 429, ['Retry-After' => '60'])]);
        $job = $this->job('alert.firing');

        $job->handle(app(SendAlert::class));

        $job->assertReleased(60);
    }

    public function test_a_rejected_token_stops_without_retrying(): void
    {
        Http::fake(['*' => Http::response([], 404)]);
        $job = $this->job('alert.firing');

        $job->handle(app(SendAlert::class)); // no exception, so no retry

        $job->assertNotReleased();
        $this->assertFalse($this->event->fresh()->delivered);
    }

    public function test_a_recovery_waits_for_its_firing_to_land_first(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response([], 202)]);
        DeliverNocgenieAlertJob::deliverLater($this->transport, $this->event, 'msg', ['event' => 'alert.firing']);

        $recovery = $this->job('alert.resolved');
        $recovery->handle(app(SendAlert::class));
        $recovery->assertReleased(DeliverNocgenieAlertJob::ORDER_WAIT_S);
        Http::assertNothingSent();

        $this->job('alert.firing')->handle(app(SendAlert::class)); // the firing lands
        $again = $this->job('alert.resolved');
        $again->handle(app(SendAlert::class));
        $again->assertNotReleased();
        Http::assertSentCount(2);
    }

    public function test_a_firing_that_gives_up_lets_its_recovery_go(): void
    {
        Queue::fake();
        DeliverNocgenieAlertJob::deliverLater($this->transport, $this->event, 'msg', ['event' => 'alert.firing']);
        $this->job('alert.firing')->failed(new NocgenieUnavailable('NOCgenie returned HTTP 503.'));

        $this->assertFalse(Cache::has("alert:nocgenie:firing:{$this->transport->id}:{$this->event->id}"));
    }
}
