<?php

namespace App\Jobs;

use App\Actions\Alerts\NocgenieUnavailable;
use App\Actions\Alerts\SendAlert;
use App\Actions\Alerts\TransportRejected;
use App\Models\AlertEvent;
use App\Models\AlertTransport;
use App\Support\EngineLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Deliver one alert (or its recovery) to a NOCgenie transport, retrying a 5xx, 429 or dropped
 * connection for ~20 minutes so a NOCgenie deploy or a network blip doesn't lose a page.
 * Retrying is safe: NOCgenie pairs on policy.id + alert.dedupe_key, so a repeat firing only
 * bumps the count on the open alert, and a recovery with nothing open is a no-op.
 *
 * Order matters the other way, though: a recovery that lands before its firing would leave
 * the alert open in NOCgenie for good. So a firing marks itself pending, and its recovery waits
 * until the firing has landed or given up.
 */
class DeliverNocgenieAlertJob implements ShouldQueue
{
    use Queueable;

    /** Waits between attempts: 10s, 30s, 2m, 5m, then every 15m until retryUntil(). */
    public const BACKOFF = [10, 30, 120, 300, 900];

    /** How long a firing keeps trying (just past the fifth retry). */
    public const FIRING_WINDOW_S = 25 * 60;

    /** Seconds a recovery waits between checks on its firing. */
    public const ORDER_WAIT_S = 15;

    public int $timeout = 30;

    /** @param array<string, mixed> $context */
    public function __construct(
        public int $transportId,
        public int $eventId,
        public string $text,
        public array $context,
    ) {
        $this->onQueue('poll'); // where alerts are evaluated today; the workers there are quick
    }

    /** @param array<string, mixed> $context */
    public static function deliverLater(AlertTransport $transport, AlertEvent $event, string $text, array $context): void
    {
        $job = new self($transport->id, $event->id, $text, $context);
        if (! $job->isRecovery()) {
            Cache::put($job->pendingKey(), true, self::FIRING_WINDOW_S + 300);
        }
        dispatch($job);
    }

    public function handle(SendAlert $sender): void
    {
        $transport = AlertTransport::find($this->transportId);
        if ($transport === null || ! $transport->enabled) {
            $this->settle();

            return; // deleted or switched off since it was queued
        }
        if ($this->isRecovery() && Cache::has($this->pendingKey())) {
            $this->release(self::ORDER_WAIT_S); // its firing is still retrying

            return;
        }

        try {
            $sender->deliver($transport, $this->text, $this->context);
        } catch (TransportRejected $e) {
            // A bad token or a closed organisation won't fix itself; say so once and stop.
            $this->logFailure($e, 'alert: NOCgenie refused the alert');
            $this->settle();

            return;
        } catch (NocgenieUnavailable $e) {
            if ($e->retryAfter !== null && $e->retryAfter > 0) {
                $this->release(min($e->retryAfter, 900)); // honour Retry-After (a 429, say)

                return;
            }
            throw $e; // the backoff() schedule picks the next try
        }

        if (! $this->isRecovery()) {
            AlertEvent::whereKey($this->eventId)->update(['delivered' => true]);
        }
        $this->settle();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return self::BACKOFF;
    }

    /** A recovery gets longer, since it may spend the firing's whole window waiting on it. */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addSeconds($this->isRecovery() ? 2 * self::FIRING_WINDOW_S : self::FIRING_WINDOW_S);
    }

    public function failed(Throwable $e): void
    {
        $this->logFailure($e, 'alert: NOCgenie delivery gave up after retrying');
        $this->settle();
    }

    private function isRecovery(): bool
    {
        return ($this->context['event'] ?? null) === 'alert.resolved';
    }

    private function pendingKey(): string
    {
        return "alert:nocgenie:firing:{$this->transportId}:{$this->eventId}";
    }

    /** A firing is done, landed or not, so its recovery can go. */
    private function settle(): void
    {
        if (! $this->isRecovery()) {
            Cache::forget($this->pendingKey());
        }
    }

    private function logFailure(Throwable $e, string $message): void
    {
        EngineLog::error($message, [
            'transport_id' => $this->transportId,
            'alert_event_id' => $this->eventId,
            'event' => $this->context['event'] ?? null,
            'error' => $e->getMessage(), // our wording or an HTTP status - never the token
        ]);
    }
}
