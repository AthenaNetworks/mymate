<?php

namespace App\Rules;

use App\Support\OutboundHostGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Rejects a URL whose host resolves to loopback/link-local/reserved. */
class SafeOutboundUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return; // let required/url rules handle emptiness
        }

        if (! OutboundHostGuard::isSafeUrl($value)) {
            $fail('The :attribute must not point at a loopback, link-local, or reserved address.');
        }
    }
}
