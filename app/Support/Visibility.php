<?php

namespace App\Support;

use App\Models\User;

/**
 * Central gate for per-map access control (GitHub #28). Returns the current operator only when
 * they are a *restricted* user, so the global scopes on Device/Map/Link constrain their reads and
 * leave everyone else (admins, ordinary viewers) untouched.
 *
 * Crucially this is null outside an authenticated web request - the poll engine, queue workers and
 * console commands have no auth user, so background work is never scoped.
 */
class Visibility
{
    public static function restrictedUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User && $user->isRestricted() ? $user : null;
    }
}
