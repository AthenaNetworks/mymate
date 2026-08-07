<?php

namespace App\Services\Probes;

use Carbon\CarbonImmutable;

/**
 * The outcome of running one probe: reachable or not, how long it took, a short human reason, and
 * (HTTPS only) the TLS certificate expiry so a probe can double as a cert-expiry watch.
 */
class ProbeResult
{
    public function __construct(
        public readonly bool $up,
        public readonly ?float $latencyMs = null,
        public readonly ?string $message = null,
        public readonly ?CarbonImmutable $certExpiresAt = null,
    ) {}

    public static function up(float $latencyMs, ?string $message = null, ?CarbonImmutable $certExpiresAt = null): self
    {
        return new self(true, $latencyMs, $message, $certExpiresAt);
    }

    public static function down(string $message, ?float $latencyMs = null): self
    {
        return new self(false, $latencyMs, $message);
    }
}
