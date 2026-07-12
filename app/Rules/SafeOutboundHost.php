<?php

namespace App\Rules;

use App\Support\OutboundHostGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Rejects a bare host that resolves to loopback/link-local/reserved. */
class SafeOutboundHost implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return; // let required/string rules handle emptiness
        }

        if (! OutboundHostGuard::isSafeHost($value)) {
            $fail('The :attribute must not point at a loopback, link-local, or reserved address.');
        }
    }
}
