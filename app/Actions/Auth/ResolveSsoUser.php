<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Services\Sso\SsoException;
use App\Support\EngineLog;
use App\Support\RestrictedAudience;
use App\Support\SsoSettings;
use Illuminate\Support\Facades\DB;

/**
 * Turn a verified ID token into the My Mate operator it belongs to - creating, linking or
 * refusing as needed. Roles come from the provider (Entra app roles), re-checked at every
 * sign-in:
 *
 *  - The identity is the issuer + subject (Entra's object id `oid`, else `sub`), never the email.
 *  - First sign-in: an existing account with the same email that isn't linked to another identity
 *    is linked (keeping its history, API keys and password); otherwise a new account is created
 *    with no password. Only emails from the configured issuer reach here, so this is our tenant.
 *  - The role claim must hold the admin or the operator role. Without either the sign-in is
 *    refused, and an account already linked to this identity is disabled (reason 'sso'), which
 *    ends its sessions and blocks its API keys. A later sign-in with a role lifts an SSO disable;
 *    an admin's disable is never lifted here.
 *  - The admin role sets is_admin, the operator role clears it. Restricted map access and groups
 *    stay managed in My Mate (an admin is never restricted or in a group, as elsewhere).
 */
class ResolveSsoUser
{
    public function __construct(private SsoSettings $settings) {}

    /** @param  array<string,mixed>  $claims  verified ID token claims */
    public function __invoke(array $claims): User
    {
        $issuer = rtrim((string) $claims['iss'], '/');
        $subject = (string) ($claims['oid'] ?? $claims['sub']);

        $roles = $claims[$this->settings->roleClaim()] ?? [];
        $roles = is_array($roles) ? $roles : [$roles];
        $isAdmin = $this->settings->adminRole() !== '' && in_array($this->settings->adminRole(), $roles, true);
        $isOperator = $this->settings->operatorRole() !== '' && in_array($this->settings->operatorRole(), $roles, true);

        if (! $isAdmin && ! $isOperator) {
            // Outside any transaction: the disable must stick even though the sign-in then fails.
            $linked = User::where('sso_issuer', $issuer)->where('sso_subject', $subject)->whereNull('disabled_at')->first();
            if ($linked !== null) {
                $linked->forceFill(['disabled_at' => now(), 'disabled_reason' => 'sso'])->save();
                EngineLog::warning('auth: sso account disabled - no My Mate role', ['user_id' => $linked->id]);
            }
            throw new SsoException('no_role', "no {$this->settings->adminRole()} / {$this->settings->operatorRole()} role in the token for {$subject}");
        }

        return DB::transaction(function () use ($claims, $issuer, $subject, $isAdmin): User {
            $user = User::where('sso_issuer', $issuer)->where('sso_subject', $subject)->lockForUpdate()->first();

            $email = $this->email($claims);
            $created = false;

            if ($user === null) {
                if ($email === null) {
                    throw new SsoException('no_email', "the token for {$subject} has no usable email to create or link an account");
                }
                $user = User::whereRaw('lower(email) = ?', [$email])->lockForUpdate()->first();
                if ($user !== null && $user->sso_subject !== null) {
                    // Same email, but already linked to a different identity - never re-point it.
                    throw new SsoException('email_conflict', "{$email} is already linked to another SSO identity");
                }
                if ($user === null) {
                    $user = new User;
                    $user->email = $email;
                    $user->password = null; // SSO-only: no password login
                    $created = true;
                }
                $user->sso_issuer = $issuer;
                $user->sso_subject = $subject;
            }

            if ($user->disabled_at !== null) {
                if ($user->disabled_reason !== 'sso') {
                    throw new SsoException('disabled', "account {$user->id} was disabled by an administrator");
                }
                $user->disabled_at = null; // the role is back - lift the SSO disable
                $user->disabled_reason = null;
            }

            $name = trim((string) ($claims['name'] ?? ''));
            if ($name !== '') {
                $user->name = mb_substr($name, 0, 255);
            } elseif ($created) {
                $user->name = $email;
            }
            // Follow an email change at the provider, unless another account already has it.
            if (! $created && $email !== null && strtolower((string) $user->email) !== $email
                && ! User::whereRaw('lower(email) = ?', [$email])->whereKeyNot($user->id)->exists()) {
                $user->email = $email;
            }

            $wasAdmin = (bool) $user->is_admin;
            $user->is_admin = $isAdmin; // explicit - never mass-assigned
            if ($isAdmin) {
                $user->restricted = false;
            }
            $user->sso_last_login_at = now();
            $user->save();
            if ($isAdmin) {
                $user->groups()->detach();
            }

            if ($created || $wasAdmin !== $isAdmin) {
                RestrictedAudience::forget(); // access changed - refresh who gets which live devices
            }
            EngineLog::warning($created ? 'auth: operator created by sso' : 'auth: sso sign-in', [
                'user_id' => $user->id,
                'is_admin' => $isAdmin,
                'admin_changed' => $wasAdmin !== $isAdmin,
            ]);

            return $user;
        });
    }

    /**
     * The email to create or link by: `email`, else Entra's `preferred_username` (the UPN) when it
     * looks like an address. A provider that says the email isn't verified doesn't get to link.
     */
    private function email(array $claims): ?string
    {
        if (($claims['email_verified'] ?? null) === false) {
            return null;
        }
        foreach (['email', 'preferred_username', 'upn'] as $claim) {
            $v = strtolower(trim((string) ($claims[$claim] ?? '')));
            if ($v !== '' && filter_var($v, FILTER_VALIDATE_EMAIL) !== false) {
                return $v;
            }
        }

        return null;
    }
}
