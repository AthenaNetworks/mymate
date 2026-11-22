<?php

namespace Tests\Feature;

use App\Actions\Alerts\NocgenieUnavailable;
use App\Actions\Alerts\SendAlert;
use App\Actions\Alerts\TransportRejected;
use App\Models\AlertTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Delivery shape for each webhook-style transport type. */
class AlertTransportDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private function deliver(string $type, array $config, string $message = 'hello'): void
    {
        $transport = AlertTransport::factory()->create(['type' => $type, 'config' => $config]);
        app(SendAlert::class)->deliver($transport, $message);
    }

    public function test_generic_webhook_posts_text(): void
    {
        Http::fake();
        $this->deliver('webhook', ['webhook_url' => 'https://hook.example.test/x'], 'msg');

        Http::assertSent(fn ($req) => $req->url() === 'https://hook.example.test/x' && $req['text'] === 'msg'
            && $req['source'] === 'mymate' && $req['event'] === 'test');
    }

    public function test_teams_posts_an_adaptive_card_for_workflows_webhooks(): void
    {
        Http::fake();
        $this->deliver('teams', ['webhook_url' => 'https://example.westus.logic.azure.com/workflows/x'], 'Device CORE is down.');
        Http::assertSent(fn ($req) => $req['type'] === 'message'
            && $req['attachments'][0]['contentType'] === 'application/vnd.microsoft.card.adaptive'
            && $req['attachments'][0]['content']['type'] === 'AdaptiveCard'
            && $req['attachments'][0]['content']['body'][0]['text'] === 'Device CORE is down.'
            && $req['text'] === 'Device CORE is down.');
    }

    public function test_discord_posts_a_content_field(): void
    {
        Http::fake();
        $this->deliver('discord', ['webhook_url' => 'https://discord.example.test/wh']);

        Http::assertSent(fn ($req) => $req['content'] === 'hello' && ! isset($req['text']));
    }

    public function test_telegram_calls_the_bot_api(): void
    {
        Http::fake();
        $this->deliver('telegram', ['telegram_token' => 'ABC', 'telegram_chat_id' => '999'], 'ping');

        Http::assertSent(fn ($req) => str_contains($req->url(), 'api.telegram.org/botABC/sendMessage')
            && $req['chat_id'] === '999' && $req['text'] === 'ping');
    }

    public function test_pagerduty_triggers_an_event(): void
    {
        Http::fake();
        $this->deliver('pagerduty', ['pagerduty_key' => 'RKEY'], 'boom');

        Http::assertSent(fn ($req) => str_contains($req->url(), 'events.pagerduty.com/v2/enqueue')
            && $req['routing_key'] === 'RKEY'
            && $req['event_action'] === 'trigger'
            && $req['payload']['summary'] === 'boom');
    }

    public function test_nocgenie_posts_to_the_hosted_inbound_api_with_its_token_in_a_header(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Accepted.'], 202)]);
        $this->deliver('nocgenie', ['nocgenie_token' => 'NGTOKEN', 'nocgenie_url' => null], 'msg');

        Http::assertSent(fn ($req) => $req->url() === 'https://app.nocgenie.com/api/v1/inbound'
            && $req->header('X-NOCgenie-Token') === ['NGTOKEN']
            && $req['text'] === 'msg' && $req['source'] === 'mymate' && $req['event'] === 'test'
            && ! str_contains($req->url(), 'NGTOKEN'));
    }

    public function test_nocgenie_can_point_at_a_self_hosted_instance(): void
    {
        Http::fake(['*' => Http::response([], 202)]);
        $this->deliver('nocgenie', ['nocgenie_token' => 'T', 'nocgenie_url' => 'https://noc.example.test/']);

        Http::assertSent(fn ($req) => $req->url() === 'https://noc.example.test/api/v1/inbound');
    }

    public function test_nocgenie_tries_once_and_flags_a_server_error_or_rate_limit_as_worth_retrying(): void
    {
        Http::fake(['*' => Http::response([], 429, ['Retry-After' => '42'])]);

        try {
            $this->deliver('nocgenie', ['nocgenie_token' => 'T']);
            $this->fail('expected NocgenieUnavailable');
        } catch (NocgenieUnavailable $e) {
            $this->assertSame(42, $e->retryAfter);
        }
        Http::assertSentCount(1); // retrying is the job's business, not this call's
    }

    public function test_nocgenie_rejects_a_bad_token_without_echoing_it(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Unknown token.'], 404)]);

        try {
            $this->deliver('nocgenie', ['nocgenie_token' => 'WRONG']);
            $this->fail('expected the delivery to be rejected');
        } catch (TransportRejected $e) {
            $this->assertStringContainsString('token', $e->getMessage());
            $this->assertStringNotContainsString('WRONG', $e->getMessage());
        }
    }

    public function test_nocgenie_reports_a_closed_organisation(): void
    {
        Http::fake(['*' => Http::response([], 410)]);

        $this->expectException(TransportRejected::class);
        $this->expectExceptionMessage('organisation is closed');
        $this->deliver('nocgenie', ['nocgenie_token' => 'T']);
    }
}
