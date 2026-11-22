<?php

namespace App\Actions\Alerts;

use App\Enums\TransportType;
use App\Models\AlertEvent;
use App\Models\AlertPolicy;
use App\Models\AlertTransport;
use App\Support\EngineLog;
use App\Support\MailSettings;
use App\Support\OutboundHostGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Deliver one alert to every enabled transport on its policy.
 * Per-transport failures are isolated + logged (never the secret). Marks the event
 * delivered if at least one transport accepted it.
 */
class SendAlert
{
    public function __construct(private MailSettings $mail) {}

    public function send(AlertPolicy $policy, AlertEvent $event): void
    {
        $delivered = false;
        $text = $this->firingText($event);
        $context = $this->webhookContext('alert.firing', $policy, $event);

        foreach ($policy->transports->where('enabled', true) as $transport) {
            try {
                $this->deliver($transport, $text, $context);
                $delivered = true;
            } catch (\Throwable $e) {
                EngineLog::error('alert: transport failed', [
                    'transport_id' => $transport->id,
                    'type' => $transport->type->value,
                    'error' => $e->getMessage(), // message only - never the config/secret
                ]);
            }
        }

        $event->update(['delivered' => $delivered]);
    }

    /**
     * Notify that a previously-firing condition has cleared (recovery alert). Same
     * transports as the firing alert, prefixed so it reads as a resolution. Best-effort
     * + isolated per transport, like {@see send()}.
     */
    public function sendRecovery(AlertPolicy $policy, AlertEvent $event): void
    {
        $message = $this->recoveryText($event);
        $context = $this->webhookContext('alert.resolved', $policy, $event);

        foreach ($policy->transports->where('enabled', true) as $transport) {
            try {
                $this->deliver($transport, $message, $context);
            } catch (\Throwable $e) {
                EngineLog::error('alert: recovery transport failed', [
                    'transport_id' => $transport->id,
                    'type' => $transport->type->value,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Firing text with how long the breach was sustained before it fired,
     * e.g. "Device X is down. (sustained 10 min)". Zero for instant (duration_minutes=0)
     * policies - breach_started_at and fired_at are the same timestamp there - so no
     * pointless "(sustained 0 min)" is appended.
     */
    private function firingText(AlertEvent $event): string
    {
        if ($event->breach_started_at === null) {
            return $event->message;
        }

        $minutes = (int) $event->breach_started_at->diffInMinutes($event->fired_at ?? now());

        return $minutes > 0 ? "{$event->message} (sustained {$minutes} min)" : $event->message;
    }

    /**
     * Recovery text, prefixed with the real outage length - from when the breach
     * actually started to when the condition actually cleared (`recovery_started_at`),
     * not counting the recovery-confirmation wait itself. "Resolved after N min: ..." reads
     * naturally regardless of condition wording (device down, high util, ...). Instant
     * policies never set `recovery_started_at`, so they fall back to the original
     * unprefixed "Resolved: ..." (unchanged behaviour).
     */
    private function recoveryText(AlertEvent $event): string
    {
        if ($event->breach_started_at !== null && $event->recovery_started_at !== null) {
            $minutes = (int) $event->breach_started_at->diffInMinutes($event->recovery_started_at);
            if ($minutes > 0) {
                return "✅ Resolved after {$minutes} min: {$event->message}";
            }
        }

        return '✅ Resolved: '.$event->message;
    }

    /**
     * The structured part of a generic Webhook POST, sent beside `text` so a receiver
     * (an on-call tool, a script) can pair a recovery with its alert without parsing the
     * wording. `alert.dedupe_key` is stable for the life of the condition; `message`
     * can change while it fires (a latency or utilisation figure), so match on the key.
     *
     * @return array<string, mixed>
     */
    private function webhookContext(string $kind, AlertPolicy $policy, AlertEvent $event): array
    {
        $condition = $policy->condition;

        return [
            'source' => 'mymate',
            'version' => 1,
            'event' => $kind,
            'instance' => rtrim((string) config('app.url'), '/'),
            'alert' => [
                'id' => $event->id,
                'dedupe_key' => $event->dedupe_key,
                'status' => $kind === 'alert.resolved' ? 'resolved' : 'firing',
                'message' => $event->message,
                'breach_started_at' => $event->breach_started_at?->toIso8601String(),
                'fired_at' => $event->fired_at?->toIso8601String(),
                'resolved_at' => $kind === 'alert.resolved' ? ($event->resolved_at ?? now())->toIso8601String() : null,
                'url' => rtrim((string) config('app.url'), '/').'/alerts',
            ],
            'policy' => [
                'id' => $policy->id,
                'name' => $policy->name,
                'condition' => $condition instanceof \BackedEnum ? $condition->value : $condition,
            ],
        ];
    }

    /**
     * Send one message through one transport. `config` is the decrypted array. Only the
     * generic Webhook type carries `$context` (Slack, Teams and Discord reject or ignore
     * unknown fields, and they're for people, not programs).
     *
     * @param  array<string, mixed>|null  $context
     */
    public function deliver(AlertTransport $transport, string $message, ?array $context = null): void
    {
        $config = $transport->config;

        match ($transport->type) {
            // Use the operator's SMTP server when configured; else the
            // default mailer (env). apply() returns the mailer name or null (= default).
            TransportType::Email => Mail::mailer($this->mail->apply())->raw($message, function ($m) use ($config): void {
                $m->to($config['email'])->subject('My Mate alert');
            }),
            // Slack, Teams, Messenger and a generic Webhook all take an incoming webhook that
            // accepts a {"text": ...} POST - one path for every such URL transport.
            //  SSRF hardening: re-check the target immediately before sending (closes
            // the DNS-rebinding gap since save-time validation) and never follow a redirect
            // (a validated public URL could otherwise 302 to an internal target).
            TransportType::Slack, TransportType::Teams, TransportType::Messenger => $this->deliverWebhook($config, $message),
            // The generic Webhook also gets the structured fields (see webhookContext()).
            TransportType::Webhook => $this->deliverWebhook($config, $message, extra: $context ?? ['source' => 'mymate', 'version' => 1, 'event' => 'test']),
            // Discord incoming webhooks want the message under "content", not "text".
            TransportType::Discord => $this->deliverWebhook($config, $message, key: 'content'),
            TransportType::Telegram => $this->deliverTelegram($config, $message),
            TransportType::Pagerduty => $this->deliverPagerduty($config, $message),
        };
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $extra
     */
    private function deliverWebhook(array $config, string $message, string $key = 'text', array $extra = []): void
    {
        $url = (string) ($config['webhook_url'] ?? '');

        if (! OutboundHostGuard::isSafeUrl($url)) {
            throw new RuntimeException('Webhook target is not a safe outbound destination.');
        }

        Http::asJson()->withoutRedirecting()->post($url, [$key => $message] + $extra)->throw();
    }

    /**
     * Telegram Bot API. Host is fixed (api.telegram.org), so it's not routed through
     * OutboundHostGuard (that guards operator-supplied URLs, not a known trusted host).
     *
     * @param  array<string, mixed>  $config
     */
    private function deliverTelegram(array $config, string $message): void
    {
        $token = (string) ($config['telegram_token'] ?? '');
        $chatId = (string) ($config['telegram_chat_id'] ?? '');
        if ($token === '' || $chatId === '') {
            throw new RuntimeException('Telegram transport is missing its bot token or chat id.');
        }

        Http::asJson()->post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id' => $chatId,
            'text' => $message,
        ])->throw();
    }

    /**
     * PagerDuty Events API v2 (fixed host). A trigger event with the message as the summary.
     *
     * @param  array<string, mixed>  $config
     */
    private function deliverPagerduty(array $config, string $message): void
    {
        $routingKey = (string) ($config['pagerduty_key'] ?? '');
        if ($routingKey === '') {
            throw new RuntimeException('PagerDuty transport is missing its routing key.');
        }

        Http::asJson()->post('https://events.pagerduty.com/v2/enqueue', [
            'routing_key' => $routingKey,
            'event_action' => 'trigger',
            'payload' => [
                'summary' => mb_substr($message, 0, 1024),
                'source' => 'My Mate',
                'severity' => 'error',
            ],
        ])->throw();
    }
}
