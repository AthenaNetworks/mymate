<?php

namespace App\Services\Discovery;

use App\Enums\PollMethod;

/**
 * Outcome of probing one responder against the credential pool. `method` is the poll method
 * that identified it (SNMP or RouterOS) or null when neither matched. `sshCredentialId` is a
 * separately-matched SSH credential (for config backups) - a host can have both a poll
 * credential and an SSH one, and both are linked onto the device on promotion.
 *
 * Carries only credential *ids* (never secrets) so it's safe to log/serialise.
 */
final readonly class ProbeResult
{
    /**
     * @param  list<int>  $matchedCredentialIds  every credential that authenticated (poll + SSH),
     *                                            for display as tags in the review queue
     */
    public function __construct(
        public ?PollMethod $method,
        public ?string $sysname,
        public ?int $credentialId,
        public ?int $sshCredentialId = null,
        public array $matchedCredentialIds = [],
    ) {}

    public static function none(): self
    {
        return new self(null, null, null, null, []);
    }

    /** Did a poll credential (SNMP or RouterOS) identify the host? */
    public function identified(): bool
    {
        return $this->method !== null;
    }

    /** Did *any* credential (poll or SSH) match? Then it's worth queuing as a candidate. */
    public function matchedAny(): bool
    {
        return $this->method !== null || $this->sshCredentialId !== null;
    }
}
