<?php

namespace App\Actions\Alerts;

use App\Enums\TransportType;
use App\Jobs\DeliverNocgenieAlertJob;
use App\Models\AlertEvent;
use App\Models\AlertPolicy;
use App\Models\AlertTransport;
use App\Models\Device;
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
    /** The hosted NOCgenie service; a transport can point at a self-hosted one instead. */
    public const NOCGENIE_URL = 'https://app.nocgenie.com';

    public function __construct(private MailSettings $mail) {}

    public function send(AlertPolicy $policy, AlertEvent $event): void
    {
        $delivered = false;
        $queued = false;
        $text = $this->firingText($event);
        $context = $this->webhookContext('alert.firing', $policy, $event);

        foreach ($policy->transports->where('enabled', true) as $transport) {
            try {
                if ($transport->type === TransportType::Nocgenie) {
                    // A pager mustn't lose a page to a short outage, so NOCgenie goes through a
                    // queued job that retries for ~20 min; it marks the event delivered itself.
                    DeliverNocgenieAlertJob::deliverLater($transport, $event, $text, $context);
                    $queued = true;

                    continue;
                }
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

        // A queued NOCgenie job marks the event delivered when it lands; don't undo that here.
        if ($delivered || ! $queued) {
            $event->update(['delivered' => $delivered]);
        }
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
                if ($transport->type === TransportType::Nocgenie) {
                    DeliverNocgenieAlertJob::deliverLater($transport, $event, $message, $context);

                    continue;
                }
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
        ] + $this->subjectContext($event) + [
            'sustained_minutes' => $event->breach_started_at !== null
                ? (int) $event->breach_started_at->diffInMinutes($event->fired_at ?? now())
                : 0,
        ];
    }

    /**
     * The device (and interface) an alert is about, read off its dedupe key the way
     * AlertStateChanged does (`device:12`, `device:12:iface:40`, `device:12:probe:3`). Link,
     * agent and discovery alerts have no single device, so they send neither block.
     *
     * @return array<string, mixed>
     */
    private function subjectContext(AlertEvent $event): array
    {
        if (! preg_match('/^device:(\d+)/', $event->dedupe_key, $m)) {
            return [];
        }
        $device = Device::with('site')->find((int) $m[1]);
        if ($device === null) {
            return [];
        }
        $base = rtrim((string) config('app.url'), '/');
        $out = ['device' => [
            'id' => $device->id,
            'name' => $device->name,
            'mgmt_ip' => $device->mgmt_ip,
            'type' => $device->device_type?->value,
            'vendor' => $device->vendor,
            'model' => $device->model,
            'site' => $device->site?->name,
            'url' => "{$base}/devices/{$device->id}",
        ]];
        if (preg_match('/:iface:(\d+)/', $event->dedupe_key, $i)) {
            $name = $device->interfaces()->whereKey((int) $i[1])->value('name');
            if ($name !== null) {
                $out['interface'] = ['name' => $name];
            }
        }

        return $out;
    }

    /**
     * Send one message through one transport. `config` is the decrypted array. Only the
     * generic Webhook and NOCgenie types carry `$context` (Slack, Teams and Discord reject or ignore
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
            TransportType::Webhook => $this->deliverWebhook($config, $message, extra: $context ?? self::testContext()),
            // NOCgenie takes the same body as the generic Webhook, with its token in a header.
            TransportType::Nocgenie => $this->deliverNocgenie($config, $message, $context ?? self::testContext()),
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

    /** What the Test button sends in place of an alert's structured fields. @return array<string, mixed> */
    private static function testContext(): array
    {
        return ['source' => 'mymate', 'version' => 1, 'event' => 'test', 'instance' => rtrim((string) config('app.url'), '/')];
    }

    /**
     * One attempt at NOCgenie's inbound API: the generic Webhook body POSTed to
     * `<base>/api/v1/inbound` with the integration token in `X-NOCgenie-Token`. The base
     * defaults to the hosted service; a self-hosted one is guarded like any operator-supplied
     * URL. Retrying is the caller's job ({@see DeliverNocgenieAlertJob}; the Test button tries
     * once): a 5xx or 429 throws {@see NocgenieUnavailable}, a dropped connection throws
     * ConnectionException, and anything that won't fix itself throws {@see TransportRejected}.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $context
     */
    private function deliverNocgenie(array $config, string $message, array $context): void
    {
        $token = (string) ($config['nocgenie_token'] ?? '');
        if ($token === '') {
            throw new TransportRejected('NOCgenie transport is missing its integration token.');
        }
        $base = rtrim((string) (($config['nocgenie_url'] ?? '') ?: self::NOCGENIE_URL), '/');
        if (! OutboundHostGuard::isSafeUrl($base)) {
            throw new TransportRejected('NOCgenie URL is not a safe outbound destination.');
        }

        $res = Http::asJson()->acceptJson()->withoutRedirecting()->connectTimeout(5)->timeout(15)
            ->withHeaders(['X-NOCgenie-Token' => $token])
            ->post("{$base}/api/v1/inbound", ['text' => $message] + $context);

        if ($res->successful()) {
            return; // 202, including an unparseable body NOCgenie kept for review
        }
        if ($res->serverError() || $res->status() === 429) {
            $wait = $res->header('Retry-After');
            throw new NocgenieUnavailable("NOCgenie returned HTTP {$res->status()}.", is_numeric($wait) ? (int) $wait : null);
        }

        throw new TransportRejected(match ($res->status()) {
            401, 404 => 'NOCgenie rejected the integration token (missing, unknown or disabled).',
            410 => 'NOCgenie refused the alert: the organisation is closed.',
            default => "NOCgenie refused the alert (HTTP {$res->status()}).",
        });
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
