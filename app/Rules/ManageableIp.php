<?php

namespace App\Rules;

use App\Support\OutboundHostGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects a device management IP that points at loopback / link-local / multicast /
 * reserved space. Reuses OutboundHostGuard's range classification - the
 * same "is this a sane routable target" logic hardened in  - so there is
 * one source of truth. RFC1918 and normal public IPs pass (the real fleet is mostly
 * RFC1918). This blocks obvious mistakes like `127.0.0.1` (the monitor box itself) at
 * the door; it cannot reject a *valid public* IP that merely isn't a real device
 * (e.g. `1.1.1.1`) - that stays a manual data-cleanup decision.
 */
class ManageableIp implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return; // let required/ip rules handle emptiness/format
        }

        if (! OutboundHostGuard::isSafeHost($value)) {
            $fail('The :attribute must be a routable address, not a loopback, link-local, multicast, or reserved one.');
        }
    }
}
