<?php

namespace App\Support;

use App\Exceptions\ImportCancelled;
use App\Models\ImportRun;

/**
 * Progress + cancellation channel for a running Dude import. Writes are throttled
 * (~1/s) so frequent updates from the history loop don't hammer the DB, while stage
 * changes always flush immediately. The same throttle gates the cancel-flag re-read,
 * so {@see checkCancelled()} is cheap to call in a tight loop.
 *
 * Overall percent: extraction + config occupy 0-15%, history 15-100% (history is the
 * long pole). ETA comes from the history byte-throughput rate.
 */
class ImportProgress
{
    private const CONFIG_CEILING = 15.0;  // % reserved for extraction + config stages

    private float $lastWrite = 0.0;

    private float $lastCancelCheck = 0.0;

    private bool $cancelled = false;

    public function __construct(private ImportRun $run) {}

    /** Set the current stage label + overall percent; flushes immediately. */
    public function stage(string $label, float $percent, ?string $detail = null): void
    {
        $this->write($label, $percent, $detail, null, force: true);
    }

    /**
     * Report fine-grained history progress (throttled). $fraction is 0..1 of the
     * history bytes consumed; $elapsed is seconds spent on history so far.
     */
    public function history(float $fraction, float $elapsed, string $detail): void
    {
        $fraction = max(0.0, min(1.0, $fraction));
        $percent = self::CONFIG_CEILING + (100.0 - self::CONFIG_CEILING) * $fraction;
        $eta = ($fraction > 0.02 && $elapsed > 0)
            ? (int) round($elapsed * (1 - $fraction) / $fraction)
            : null;
        $this->write('Importing history', $percent, $detail, $eta, force: false);
    }

    /** Throw {@see ImportCancelled} if the operator requested a stop (throttled re-read). */
    public function checkCancelled(): void
    {
        $now = microtime(true);
        if (! $this->cancelled && $now - $this->lastCancelCheck >= 1.0) {
            $this->lastCancelCheck = $now;
            $this->cancelled = (bool) ImportRun::where('id', $this->run->id)->value('cancel_requested');
        }
        if ($this->cancelled) {
            throw new ImportCancelled;
        }
    }

    private function write(string $label, float $percent, ?string $detail, ?int $eta, bool $force): void
    {
        $now = microtime(true);
        if (! $force && $now - $this->lastWrite < 1.0) {
            return;
        }
        $this->lastWrite = $now;

        $this->run->forceFill([
            'stage' => $label,
            'progress' => [
                'percent' => round($percent, 1),
                'detail' => $detail,
                'eta_seconds' => $eta,
            ],
        ])->save();
    }
}
