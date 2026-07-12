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

        foreach ($policy->transports->where('enabled', true) as $transport) {
            try {
                $this->deliver($transport, $text);
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

        foreach ($policy->transports->where('enabled', true) as $transport) {
            try {
                $this->deliver($transport, $message);
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

    /** Send one message through one transport. `config` is the decrypted array. */
    public function deliver(AlertTransport $transport, string $message): void
    {
        $config = $transport->config;

        match ($transport->type) {
            // Use the operator's SMTP server when configured; else the
            // default mailer (env). apply() returns the mailer name or null (= default).
            TransportType::Email => Mail::mailer($this->mail->apply())->raw($message, function ($m) use ($config): void {
                $m->to($config['email'])->subject('My Mate alert');
            }),
            // Slack, Teams, and Messenger all take an
            // incoming webhook that accepts a {"text": ...} POST - one path for every URL transport.
            //  SSRF hardening: re-check the target immediately before sending (closes
            // the DNS-rebinding gap since save-time validation) and never follow a redirect
            // (a validated public URL could otherwise 302 to an internal target).
            TransportType::Slack, TransportType::Teams, TransportType::Messenger => $this->deliverWebhook($config, $message),
        };
    }

    /** @param array<string, mixed> $config */
    private function deliverWebhook(array $config, string $message): void
    {
        $url = (string) ($config['webhook_url'] ?? '');

        if (! OutboundHostGuard::isSafeUrl($url)) {
            throw new RuntimeException('Webhook target is not a safe outbound destination.');
        }

        Http::asJson()->withoutRedirecting()->post($url, ['text' => $message])->throw();
    }
}
