<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Ways back in for an operator who's lost their passkey(s):
 *  - one-time recovery codes they saved when they set passkeys up (self-service), and
 *  - a reset by an admin, or on the server for a sole admin (`mymate:user:reset-passkeys`).
 *
 * Codes are stored as hashes only and each works once. A code counts as the second factor for
 * that session - the password still has to be right first.
 */
class PasskeyRecovery
{
    public const CODE_COUNT = 8;

    /**
     * Replace the operator's recovery codes with a fresh set and return them (the only time the
     * plaintext exists).
     *
     * @return list<string>
     */
    public function generate(User $user): array
    {
        $codes = [];
        for ($i = 0; $i < self::CODE_COUNT; $i++) {
            $codes[] = implode('-', str_split(Str::lower(Str::random(12)), 4));
        }
        $user->forceFill(['passkey_recovery_codes' => array_map(fn ($c) => Hash::make($c), $codes)])->save();

        return $codes;
    }

    public function remaining(User $user): int
    {
        return count((array) $user->passkey_recovery_codes);
    }

    /** Use up a code. True if it was one of theirs (and it's now gone). */
    public function consume(User $user, string $code): bool
    {
        $code = Str::lower(preg_replace('/[^a-z0-9]/i', '', $code) ?? '');
        if (strlen($code) !== 12) {
            return false;
        }
        $code = implode('-', str_split($code, 4));
        $hashes = (array) $user->passkey_recovery_codes;
        foreach ($hashes as $i => $hash) {
            if (Hash::check($code, $hash)) {
                unset($hashes[$i]);
                $user->forceFill(['passkey_recovery_codes' => array_values($hashes)])->save();

                return true;
            }
        }

        return false;
    }

    /** Remove every passkey and recovery code, so the next password login can set up a new one. */
    public function reset(User $user): int
    {
        $n = $user->passkeys()->count();
        $user->passkeys()->delete();
        $user->forceFill(['passkey_recovery_codes' => null])->save();

        return $n;
    }
}
