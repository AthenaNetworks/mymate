<?php

namespace App\Actions\Alerts;

use RuntimeException;

/**
 * NOCgenie answered with a 5xx or 429: worth trying again. `$retryAfter` is its Retry-After
 * in seconds, when it sent one.
 */
class NocgenieUnavailable extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $retryAfter = null)
    {
        parent::__construct($message);
    }
}
