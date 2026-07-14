<?php

namespace Tests\Feature;

use App\Actions\Alerts\SendAlert;
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

        Http::assertSent(fn ($req) => $req->url() === 'https://hook.example.test/x' && $req['text'] === 'msg');
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
}
