<?php

namespace App\Services\Discovery;

use App\Enums\PollMethod;

/**
 * Outcome of probing one responder against the credential pool. `method` is null
 * when the host answered ping but matched no credential / couldn't be identified -
 * it's still recorded as a candidate (the operator may add a credential later).
 *
 * Carries only a credential *id* (never a secret) so it's safe to log/serialise.
 */
final readonly class ProbeResult
{
    public function __construct(
        public ?PollMethod $method,
        public ?string $sysname,
        public ?int $credentialId,
    ) {}

    public static function none(): self
    {
        return new self(null, null, null);
    }

    public function identified(): bool
    {
        return $this->method !== null;
    }
}
