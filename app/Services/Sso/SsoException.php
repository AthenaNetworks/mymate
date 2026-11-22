<?php

namespace App\Services\Sso;

use RuntimeException;

/**
 * A single sign-on attempt that can't go ahead. `reason` is a short, fixed code the login page
 * turns into a readable message (it travels in the URL, so never anything sensitive); the
 * exception message is the detail for the engine log.
 */
class SsoException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $detail = '')
    {
        parent::__construct($detail !== '' ? $detail : $reason);
    }
}
